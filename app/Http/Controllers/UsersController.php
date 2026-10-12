<?php

namespace App\Http\Controllers;

use App\Folder;
use App\Mailbox;
use App\Subscription;
use App\Thread;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Validator;

class UsersController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Users list.
     */
    public function users()
    {
        $this->authorize('create', 'App\User');

        return view('users/users', ['users' => $this->getUsersForSidebar(null)]);
    }

    /**
     * New user.
     */
    public function create()
    {
        $this->authorize('create', 'App\User');
        $mailboxes = auth()->user()->mailboxesCanView();

        return view('users/create', ['mailboxes' => $mailboxes]);
    }

    /**
     * Create new user.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function createSave(Request $request)
    {
        $invalid = false;
        $this->authorize('create', 'App\User');
        $auth_user = auth()->user();

        $rules = [
            'first_name' => 'required|string|max:20',
            'last_name'  => 'max:30',
            'email'      => 'required|string|email|max:100|unique:users',
            //'role'       => ['required', Rule::in(array_keys(User::$roles))],
        ];
        if ($auth_user->isAdmin()) {
            $rules['role'] = ['required', Rule::in(array_keys(User::$roles))];
        }
        if (empty($request->send_invite)) {
            $rules['password'] = 'required|string|min:8|max:255';
        }
        $validator = Validator::make($request->all(), $rules);

        if (User::mailboxEmailExists($request->email)) {
            $invalid = true;
            $validator->errors()->add('email', __('There is a mailbox with such email. Users and mailboxes can not have the same email addresses.'));
        }

        if ($invalid || $validator->fails()) {
            return redirect()->route('users.create')
                        ->withErrors($validator)
                        ->withInput();
        }

        $password = '';
        if (empty($request->send_invite)) {
            // Set password from request.
            $password = $request->password;
        } else {
            // Set some random password before sending invite.
            $password = User::generateRandomPassword();
        }

        $data = [
            'first_name' => $request->first_name,
            'last_name' => $request->last_name ?? '',
            'email' => $request->email,
            'password' => $password,
        ];

        $user = User::create($data);

        if (!$auth_user->can('changeRole', $user)) {
            $user->role = User::ROLE_USER;
        } elseif (array_key_exists($request->role, User::$roles)) {
            $user->role = $request->role;
        }

        // Set system timezone.
        $user->timezone = config('app.timezone') ?: User::DEFAULT_TIMEZONE;
        $user = \Eventy::filter('user.create_save', $user, $request);
        $user->save();

        $mailbox_ids = $request->mailboxes ?: [];

        if (!$auth_user->isAdmin()) {
            // Exlude mailboxes to which current user has no access.
            // https://github.com/freescout-help-desk/freescout/security/advisories/GHSA-w36p-9vhj-x273
            $mailbox_ids = array_intersect($mailbox_ids, $auth_user->mailboxesIdsCanView());
        }

        $user->mailboxes()->sync($mailbox_ids);
        $user->syncPersonalFolders($mailbox_ids);

        // Send invite
        if (!empty($request->send_invite)) {
            try {
                $user->sendInvite(true);
            } catch (\Exception $e) {
                // Admin is allowed to see exceptions
                \Session::flash('flash_error_floating', $e->getMessage().' — '.__('Check mail settings in "Manage » Settings » Mail Settings"'));
            }
        }

        \Session::flash('flash_success_floating', __('User created successfully'));

        return redirect()->route('users.profile', ['id' => $user->id]);
    }

    /**
     * User profile.
     */
    public function profile($id)
    {
        $user = User::findOrFail($id);
        if ($user->isDeleted()) {
            abort(404);
        }

        $this->authorize('update', $user);

        $users = $this->getUsersForSidebar($id);

        return view('users/profile', ['user' => $user, 'users' => $users]);
    }

    /**
     * The people in Settings › Users (users/columns/users), for those who may manage them;
     * non-admins don't see admins.
     */
    public function getUsersForSidebar($except_id)
    {
        if (!auth()->user()->can('create', 'App\User')) {
            return [];
        }
        $users_query = User::nonDeleted();
        if (!auth()->user()->isAdmin()) {
            $users_query->where('role', User::ROLE_USER);
        }

        return User::sortUsers($users_query->get());
    }

    /**
     * User permissions.
     */
    public function permissions($id)
    {
        $user = auth()->user();
        if (!$user->isAdmin()) {
            abort(403);
        }

        $user = User::findOrFail($id);

        if ($user->isDeleted()) {
            abort(404);
        }

        // The levels before it: Users and the person (App\Livewire\UserPermissions saves).
        return view('users/permissions', [
            'user'  => $user,
            'users' => $this->getUsersForSidebar($id),
        ]);
    }

    /**
     * User notifications settings.
     */
    public function notifications($id)
    {
        $user = User::findOrFail($id);
        $this->authorize('updateNotifications', $user);

        if ($user->isDeleted()) {
            abort(404);
        }
        
        $subscriptions = $user->subscriptions()->select('medium', 'event')->get();

        $person = '';
        if ($id != auth()->user()->id) {
            $person = $user->getFirstName(true);
        }

        $users = $this->getUsersForSidebar($id);

        return view('users/notifications', [
            'user'          => $user,
            'subscriptions' => $subscriptions,
            'person'        => $person,
            'users'         => $users,
        ]);
    }

    /**
     * Save user notifications settings.
     *
     * @param int                      $id
     * @param \Illuminate\Http\Request $request
     */
    public function notificationsSave($id, Request $request)
    {
        $user = User::findOrFail($id);
        $this->authorize('updateNotifications', $user);

        Subscription::saveFromArray($request->subscriptions, $user->id);

        \Session::flash('flash_success_floating', __('Notifications saved successfully'));

        return redirect()->route('users.notifications', ['id' => $id]);
    }

    /**
     * Users ajax controller.
     */
    public function ajax(Request $request)
    {
        $response = [
            'status' => 'error',
            'msg'    => '', // this is error message
        ];

        $auth_user = auth()->user();

        switch ($request->action) {

            // Both send and resend
            case 'send_invite':
                if (!$auth_user->isAdmin()) {
                    $response['msg'] = __('Not enough permissions');
                }
                if (empty($request->user_id)) {
                    $response['msg'] = __('Incorrect user');
                }
                if (!$response['msg']) {
                    $user = User::find($request->user_id);
                    if (!$user) {
                        $response['msg'] = __('User not found');
                    } elseif (!$user->isActive()) {
                        $response['msg'] = __('The user is disabled or deleted.');
                    } elseif ($user->invite_state == User::INVITE_STATE_ACTIVATED) {
                        $response['msg'] = __('User already accepted invitation');
                    }
                }

                if (!$response['msg']) {
                    try {
                        $user->sendInvite(true);

                        $response['status'] = 'success';
                    } catch (\Exception $e) {
                        // Admin is allowed to see exceptions.
                        $response['msg'] = $e->getMessage().' — '.__('Check mail settings in "Manage » Settings » Mail Settings"');
                    }
                }
                break;

            // Reset password
            case 'reset_password':
                if (!auth()->user()->isAdmin()) {
                    $response['msg'] = __('Not enough permissions');
                }
                if (empty($request->user_id)) {
                    $response['msg'] = __('Incorrect user');
                }
                if (!$response['msg']) {
                    $user = User::find($request->user_id);
                    if (!$user) {
                        $response['msg'] = __('User not found');
                    } elseif (!$user->isActive()) {
                        $response['msg'] = __('The user is disabled or deleted.');
                    }
                }

                if (!$response['msg']) {
                    try {
                        $reset_result = Password::broker()->sendResetLink(
                            ['id' => $request->user_id]
                        );

                        if ($reset_result == Password::RESET_LINK_SENT) {
                            $response['status'] = 'success';
                            $response['msg_success'] = __('Password reset email has been sent');
                        }
                    } catch (\Exception $e) {
                        // Admin is allowed to see exceptions.
                        $response['msg'] = $e->getMessage().' — '.__('Check mail settings in "Manage » Settings » Mail Settings"');
                    }
                }
                break;

            // Load website notifications
            case 'web_notifications':
                if (!$auth_user) {
                    $response['msg'] = __('You are not logged in');
                }
                if (!$response['msg']) {
                    $web_notifications_info = $auth_user->getWebsiteNotificationsInfo(false);
                    $response['html'] = view('users/partials/web_notifications', [
                        'web_notifications_info_data' => $web_notifications_info['data'],
                    ])->render();

                    $response['has_more_pages'] = (int) $web_notifications_info['notifications']->hasMorePages();

                    $response['status'] = 'success';
                }
                break;

            // Mark all user website notifications as read
            case 'mark_notifications_as_read':
                if (!$auth_user) {
                    $response['msg'] = __('You are not logged in');
                }
                if (!$response['msg']) {
                    $auth_user->unreadNotifications()->update(['read_at' => now()]);
                    $auth_user->clearWebsiteNotificationsCache();

                    $response['status'] = 'success';
                }
                break;

            // Delete user photo
            // Delete user
            case 'delete_user':
                $user = User::find($request->user_id);

                if (!$user) {
                    $response['msg'] = __('User not found');
                } elseif (!$auth_user->can('delete', $user)) {
                    $response['msg'] = __('Not enough permissions');
                } elseif ($auth_user->id == $user->id) {
                    // Do not allow admin delete himself.
                    $response['msg'] = __('Not enough permissions');
                }

                // Check if the user is the only one admin
                // - not needed as only admin can delete  users and
                // current admin can not delete himself.
                // if (!$response['msg'] && $user->isAdmin()) {
                //     $admins_count = User::where('role', User::ROLE_ADMIN)->count();
                //     if ($admins_count < 2) {
                //         $response['msg'] = __('Administrator can not be deleted');
                //     }
                // }

                if (!$response['msg']) {

                    $user->deleteUser($auth_user, $request->assign_user);

                    \Session::flash('flash_success_floating', __('User deleted').': '.htmlspecialchars($user->getFullName()));

                    $response['status'] = 'success';
                }
                break;

            default:
                $response['msg'] = 'Unknown action';
		        $response = \Eventy::filter('users.ajax.response_default', $response, $request);
                break;
        }

        if ($response['status'] == 'error' && empty($response['msg'])) {
            $response['msg'] = 'Unknown error occurred';
        }

        return \Response::json($response);
    }

    /**
     * The user's own preferences: language, timezone, time format, keyboard shortcuts,
     * the status of a reply, where to go after sending, how each channel's conversations
     * look, the languages that aren't translated and the accent.
     */
    public function preferences($id)
    {
        $user = auth()->user();
        if ($user->id != $id) {
            abort(403);
        }

        return view('users/preferences', ['user' => $user]);
    }

    public function preferencesSave($id, Request $request)
    {
        $user = auth()->user();
        if ($user->id != $id) {
            abort(403);
        }

        $request->validate([
            'reply_status' => 'nullable|in:'.implode(',', array_keys(\App\Conversation::$statuses)),
            'stay_in_conversation' => 'nullable|boolean',
            'accent'       => ['nullable', \Illuminate\Validation\Rule::in(\FruitUI\Fruit::ACCENTS)],
            'locale'       => ['nullable', Rule::in(config('app.locales'))],
            'timezone'     => 'nullable|string|max:255',
            'time_format'  => ['nullable', Rule::in([User::TIME_FORMAT_12, User::TIME_FORMAT_24])],
            'languages'    => 'nullable|array',
            'languages.*'  => [Rule::in(array_keys(\App\Ai\Settings::LANGUAGES))],
        ]);
        if ($request->filled('locale')) {
            $user->locale = $request->locale;
            // Used right away (Localize middleware).
            session()->put('user_locale', $user->locale);
            \Helper::setLocale($user->locale);
        }
        if ($request->filled('timezone')) {
            $user->timezone = \Helper::stripTags($request->timezone);
        }
        if ($request->filled('time_format')) {
            $user->time_format = (int) $request->time_format;
        }
        if ($request->exists('keyboard_shortcuts_shown')) {
            $user->keyboard_shortcuts = (bool) $request->keyboard_shortcuts;
        }
        if ($request->exists('languages_shown')) {
            $user->languages = array_values(array_unique((array) $request->languages)) ?: null;
        }
        $user->reply_status = $request->reply_status ?: null;
        $user->stay_in_conversation = (bool) $request->stay_in_conversation;
        // Off: the installation's accent (Settings » Appearance).
        $user->accent = $request->filled('accent_default') ? null : ($request->accent ?: null);
        $user->save();

        \Session::flash('flash_success_floating', __('Settings updated'));

        return redirect()->route('users.preferences', ['id' => $id]);
    }

    /**
     * Change user password.
     */
    public function password($id)
    {
        $user = User::findOrFail($id);
        $this->authorize('update', $user);

        $users = User::all()->except($id);

        return view('users/password', ['user' => $user, 'users' => $users]);
    }

    /**
     * Save changed user password.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function passwordSave($id, Request $request)
    {
        // It is allowed to edit only your own password
        $user = auth()->user();
        if ($user->id != $id) {
            abort(403);
        }

        // This is also present in PublicController::userSetup
        $validator = Validator::make($request->all(), [
            'password_current' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        $validator->after(function ($validator) use ($user, $request) {
            // Check current password
            if (!Hash::check($request->password_current, $user->password)) {
                $validator->errors()->add('password_current', __('This password is incorrect.'));
            } elseif (Hash::check($request->password, $user->password)) {
                // Check new password
                $validator->errors()->add('password', __('The new password is the same as the old password.'));
            }
        });

        if ($validator->fails()) {
            return redirect()->route('users.password', ['id' => $id])
                        ->withErrors($validator)
                        ->withInput();
        }

        $user->password = bcrypt($request->password);
        $user->save();

        $user->sendPasswordChanged();

        \Session::flash('flash_success_floating', __('Password saved successfully!'));

        return redirect()->route('users.profile', ['id' => $id]);
    }
}
