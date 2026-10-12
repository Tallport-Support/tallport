<?php

namespace App\Livewire;

use App\Mailbox;
use App\User;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A user's permissions (Settings › Users › the user › Permissions), for admins: the mailboxes
 * they open and, for non-admins, each permission as the default (Settings › General), Allowed or
 * Not Allowed. Every change is saved as it's made.
 */
class UserPermissions extends Component
{
    #[Locked]
    public $user_id;

    /**
     * IDs of the mailboxes the user opens.
     */
    public $mailboxes = [];

    /**
     * Permission ID => '' (the default), '1' (Allowed) or '0' (Not Allowed).
     */
    public $permissions = [];

    public function mount($user)
    {
        $this->user_id = $user->id;
        $user = $this->user();
        $this->mailboxes = $user->mailboxes()->pluck('mailboxes.id')->map(fn ($id) => (string) $id)->all();
        foreach (User::getUserPermissionsList() as $permission_id) {
            $own = $user->permissions[$permission_id] ?? null;
            $this->permissions[$permission_id] = $own === null ? '' : ($own ? '1' : '0');
        }
    }

    /**
     * The user, for an admin; not a deleted one.
     */
    protected function user()
    {
        abort_unless(auth()->user() && auth()->user()->isAdmin(), 403);
        $user = User::findOrFail($this->user_id);
        abort_if($user->isDeleted(), 404);

        return $user;
    }

    /**
     * One change, saved on its own: the mailboxes, or one permission.
     */
    public function updated($property)
    {
        if ($property == 'mailboxes' || str_starts_with($property, 'mailboxes.')) {
            $this->saveMailboxes();
        } elseif (preg_match('/^permissions\.(\d+)$/', $property, $m)) {
            $this->savePermission((int) $m[1]);
        }
    }

    /**
     * All mailboxes, or none.
     */
    public function selectAllMailboxes($all)
    {
        $this->mailboxes = $all ? Mailbox::pluck('id')->map(fn ($id) => (string) $id)->all() : [];
        $this->saveMailboxes();
    }

    protected function saveMailboxes()
    {
        $user = $this->user();
        $ids = Mailbox::whereIn('id', array_map('intval', (array) $this->mailboxes))->pluck('id')->all();
        $current = $user->mailboxes()->pluck('mailboxes.id')->all();
        // Only what changed: the other mailboxes keep their settings for the user (mailbox_user).
        if ($removed = array_diff($current, $ids)) {
            $user->mailboxes()->detach($removed);
        }
        if ($added = array_diff($ids, $current)) {
            $user->mailboxes()->attach($added);
        }
        $user->syncPersonalFolders($ids);
        $this->mailboxes = array_map('strval', $ids);
    }

    protected function savePermission($permission_id)
    {
        $user = $this->user();
        $value = (string) ($this->permissions[$permission_id] ?? '');
        if (!in_array($permission_id, User::getUserPermissionsList()) || !in_array($value, ['', '0', '1'], true)) {
            $this->permissions[$permission_id] = '';

            return;
        }
        // Allowed and Not Allowed are the user's own; the default follows Settings » General.
        $permissions = $user->permissions ?: [];
        if ($value === '') {
            unset($permissions[$permission_id]);
        } else {
            $permissions[$permission_id] = (int) $value;
        }
        $user->permissions = $permissions;
        $user->save();
    }

    public function render()
    {
        return view('livewire.user-permissions', [
            'user'          => $this->user(),
            'all_mailboxes' => Mailbox::all()->sortBy('name'),
        ]);
    }
}
