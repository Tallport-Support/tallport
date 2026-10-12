<?php

namespace App\Livewire;

use App\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * A user's profile, two ways: one's own Profile (/profile: photo, name, job title, phone, alternate
 * emails), and an account managed in Settings › Users › the person (name and email, role, sign-in,
 * limits; rows to their permissions and security; the account's actions). Every change is saved as
 * it's made, once it passes; one that doesn't shows why on its row and leaves the saved value.
 */
class UserProfile extends Component
{
    use WithFileUploads;

    /**
     * The fields saved as they are (User::setData()).
     */
    const PROFILE_FIELDS = ['first_name', 'last_name', 'email', 'emails', 'job_title', 'phone'];

    /**
     * What each way changes: one's own profile, and an account managed.
     */
    const OWN_FIELDS = ['first_name', 'last_name', 'emails', 'job_title', 'phone', 'photo'];
    const MANAGE_FIELDS = ['first_name', 'last_name', 'email', 'role', 'disabled', 'only_assigned', 'ai_drafts_per_day'];

    /**
     * Fields only an admin changes.
     */
    const ADMIN_FIELDS = ['role', 'disabled', 'email', 'only_assigned', 'ai_drafts_per_day'];

    /**
     * What nobody changes about themselves: it would lock them out.
     */
    const NOT_FOR_ONESELF = ['role', 'disabled'];

    #[Locked]
    public $user_id;

    /**
     * own: one's own Profile; manage: Settings › Users › the person (users/columns/user).
     */
    #[Locked]
    public $mode = 'manage';

    /**
     * The page whose row is current (security, permissions, notifications), if any.
     */
    #[Locked]
    public $section;

    public $role;
    public $disabled = false;
    public $only_assigned = false;
    public $ai_drafts_per_day = '';
    public $first_name = '';
    public $last_name = '';
    public $email = '';
    public $emails = '';
    public $job_title = '';
    public $phone = '';
    public $photo;

    public function mount($user, $mode = 'manage', $section = null)
    {
        $this->user_id = $user->id;
        $this->mode = $mode == 'own' ? 'own' : 'manage';
        $this->section = $section;
        $user = $this->user();
        $this->role = (string) $user->role;
        $this->disabled = $user->status == User::STATUS_DISABLED;
        $this->only_assigned = $user->hasPermission(User::PERM_ONLY_ASSIGNED_TICKETS);
        $this->ai_drafts_per_day = $user->ai_drafts_per_day === null ? '' : (string) $user->ai_drafts_per_day;
        foreach (self::PROFILE_FIELDS as $field) {
            $this->$field = (string) $user->$field;
        }
    }

    /**
     * The user: oneself (own), or someone who may be managed; not a deleted one.
     */
    protected function user()
    {
        $user = User::findOrFail($this->user_id);
        abort_if($user->isDeleted(), 404);
        if ($this->mode == 'own') {
            abort_unless($user->id == auth()->id(), 403);
        } else {
            $this->authorize('update', $user);
        }

        return $user;
    }

    /**
     * Whether this is the signed-in user's own record (managed in Users: what would lock them out
     * can't be changed).
     */
    public function isOneself()
    {
        return $this->user_id == auth()->id();
    }

    protected function rules()
    {
        return [
            'first_name'        => 'required|string|max:20',
            'last_name'         => 'nullable|string|max:30',
            'email'             => 'required|string|email|max:100|unique:users,email,'.$this->user_id,
            'emails'            => 'nullable|string',
            'job_title'         => 'nullable|string|max:100',
            'phone'             => 'nullable|string|max:60',
            'role'              => ['required', Rule::in(array_keys(User::$roles))],
            'disabled'          => 'boolean',
            'only_assigned'     => 'boolean',
            'ai_drafts_per_day' => 'nullable|integer|min:0|max:10000',
            'photo'             => 'nullable|image|mimes:jpeg,png,jpg,gif',
        ];
    }

    protected function validationAttributes()
    {
        return ['photo' => __('Photo')];
    }

    /**
     * One change, checked and saved on its own.
     */
    public function updated($property)
    {
        if (!in_array($property, array_merge(self::PROFILE_FIELDS, self::ADMIN_FIELDS, ['photo']))) {
            return;
        }
        $user = $this->user();
        $auth_user = auth()->user();
        $fields = $this->mode == 'own' ? self::OWN_FIELDS : self::MANAGE_FIELDS;
        if (!in_array($property, $fields) || (in_array($property, self::ADMIN_FIELDS) && !$auth_user->isAdmin())) {
            $this->mount($user, $this->mode, $this->section);

            return;
        }
        // Nobody locks themselves out, so an active admin always remains.
        if (in_array($property, self::NOT_FOR_ONESELF) && $this->isOneself()) {
            $this->mount($user, $this->mode, $this->section);
            throw ValidationException::withMessages([$property => $property == 'role' ? __('You can\'t change your own role.') : __('You can\'t turn off your own sign-in.')]);
        }
        $this->validateOnly($property);
        if ($property == 'email' && User::mailboxEmailExists($this->email)) {
            throw ValidationException::withMessages(['email' => __('There is a mailbox with such email. Users and mailboxes can not have the same email addresses.')]);
        }

        switch ($property) {
            case 'role':
                $user->role = (int) $this->role;
                break;
            case 'disabled':
                $user->status = $this->disabled ? User::STATUS_DISABLED : User::STATUS_ACTIVE;
                break;
            case 'only_assigned':
                if (!$user->isAdmin()) {
                    $this->only_assigned ? $user->addPermission(User::PERM_ONLY_ASSIGNED_TICKETS, false) : $user->removePermission(User::PERM_ONLY_ASSIGNED_TICKETS, false);
                }
                break;
            case 'ai_drafts_per_day':
                $user->ai_drafts_per_day = is_numeric($this->ai_drafts_per_day) ? (int) $this->ai_drafts_per_day : null;
                break;
            case 'photo':
                $path = $user->savePhoto($this->photo);
                $this->photo = null;
                if (!$path) {
                    throw ValidationException::withMessages(['photo' => __('Error occurred processing the image. Make sure that PHP GD extension is enabled.')]);
                }
                $user->photo_url = $path;
                break;
            default:
                $user->setData([$property => $this->$property]);
                $this->$property = (string) $user->$property;
        }

        // Modules see the profile as the form used to send it.
        $user = \Eventy::filter('user.save_profile', $user, $this->request());
        $user->save();
    }

    protected function request()
    {
        return Request::create('', 'POST', [
            'first_name' => $this->first_name, 'last_name' => $this->last_name, 'email' => $this->email, 'emails' => $this->emails,
            'job_title' => $this->job_title, 'phone' => $this->phone, 'role' => $this->role, 'disabled' => $this->disabled ? User::STATUS_DISABLED : null,
            'only_assigned_tickets' => $this->only_assigned ? 1 : null, 'ai_drafts_per_day' => $this->ai_drafts_per_day,
        ]);
    }

    public function deletePhoto()
    {
        $user = $this->user();
        $user->removePhoto();
        $user->save();
    }

    public function render()
    {
        return view('livewire.user-profile', ['user' => $this->user()]);
    }
}
