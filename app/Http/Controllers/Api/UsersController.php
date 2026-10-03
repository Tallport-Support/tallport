<?php

namespace App\Http\Controllers\Api;

use App\Api\Format;
use App\Mailbox;
use App\User;
use Illuminate\Http\Request;

class UsersController extends ApiController
{
    /**
     * GET /api/users
     */
    public function index(Request $request)
    {
        $query = User::orderBy('id', 'desc');
        if ($email = $this->param($request, 'email')) {
            $query->where('email', $email);
        }
        $query = \Eventy::filter('api.users.query', $query, $request);

        return $this->paginated($request, $query, 'users', function ($user) {
            return Format::user($user);
        });
    }

    /**
     * GET /api/users/{id}
     */
    public function show(Request $request, $id)
    {
        $user = \Eventy::filter('api.user.find', User::find($id), $request);

        return $user ? response()->json(Format::user($user)) : $this->notFound();
    }

    /**
     * GET /api/users/me: the key's owner.
     */
    public function me(Request $request)
    {
        if ($this->access()->isGlobal()) {
            return response()->json(['message' => 'Global API Key is not allowed for this method. Use an API key of a user.', '_embedded' => ['errors' => []]], 501);
        }

        return response()->json(Format::user($this->access()->user()));
    }

    /**
     * POST /api/users: a user (not an administrator), without mailboxes.
     */
    public function store(Request $request)
    {
        if (!$this->access()->isAdmin()) {
            return $this->forbiddenAdmin();
        }
        foreach (['firstName', 'lastName', 'email'] as $name) {
            if (!$this->param($request, $name)) {
                return $this->required($name);
            }
        }
        $email = \App\Email::sanitizeEmail($this->param($request, 'email'));
        if (!$email) {
            return $this->error('Invalid email: '.$this->param($request, 'email'), 'email');
        }
        if (User::where('email', $email)->exists()) {
            return $this->error('User with such email already exists', 'email');
        }
        if (Mailbox::where('email', $email)->exists()) {
            return $this->error('There is a mailbox with such email', 'email');
        }

        $password = (string) $this->param($request, 'password');
        $user = User::create([
            'first_name' => $this->param($request, 'firstName'),
            'last_name'  => $this->param($request, 'lastName'),
            'email'      => $email,
            'password'   => $password ?: \Str::random(20),
            'emails'     => (string) $this->param($request, 'alternateEmails'),
            'job_title'  => (string) $this->param($request, 'jobTitle'),
            'phone'      => (string) $this->param($request, 'phone'),
            'timezone'   => $this->param($request, 'timezone') ?: config('app.timezone'),
        ]);
        if (!$user) {
            return $this->error('Error occurred creating a user', 'email');
        }
        $user->role = User::ROLE_USER;
        $user->invite_state = $password ? User::INVITE_STATE_ACTIVATED : User::INVITE_STATE_NOT_INVITED;
        $user->save();

        if ($url = $this->param($request, 'photoUrl')) {
            $path = \Helper::downloadRemoteFileAsTmp($url);
            if ($path) {
                $photo = $user->savePhoto($path, mime_content_type($path) ?: '');
                if ($photo) {
                    $user->photo_url = $photo;
                    $user->save();
                }
                @unlink($path);
            }
        }

        return $this->created(Format::user($user->fresh()), $user->id);
    }

    /**
     * DELETE /api/users/{id}?byUserId=..&assignTo[mailbox id]=user id
     */
    public function destroy(Request $request, $id)
    {
        if (!$this->access()->isAdmin()) {
            return $this->forbiddenAdmin();
        }
        $user = \Eventy::filter('api.user.find', User::find($id), $request);
        if (!$user) {
            return $this->notFound();
        }
        if ($user->isDeleted()) {
            return $this->error('User is already deleted', 'id');
        }
        $by_user = User::find($this->param($request, 'byUserId'));
        if (!$by_user || $by_user->isDeleted()) {
            return $this->error('`byUserId` parameter is required', 'byUserId');
        }
        if ($user->isAdmin() && User::nonDeleted()->where('role', User::ROLE_ADMIN)->count() < 2) {
            return $this->error('The only administrator can not be deleted', 'id');
        }
        $user->deleteUser($by_user, (array) $this->param($request, 'assignTo', []));

        return $this->noContent();
    }
}
