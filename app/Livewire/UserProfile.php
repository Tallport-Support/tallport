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
 * A user's profile (Settings › Users › the user, and the user's own Profile): account fields for
 * admins and the profile itself; as a person's level, also the rows to their other pages and the
 * account's actions. Every change is saved as it's made, once it passes; one that
 * doesn't shows why on its row and leaves the saved value as it was.
 */
class UserProfile extends Component
{
    use WithFileUploads;

    /**
     * The fields saved as they are (User::setData()).
     */
    const PROFILE_FIELDS = ['first_name', 'last_name', 'email', 'emails', 'job_title', 'phone'];

    /**
     * Fields only an admin changes.
     */
    const ADMIN_FIELDS = ['role', 'disabled', 'email', 'only_assigned', 'ai_drafts_per_day'];

    #[Locked]
    public $user_id;

    /**
     * As a person's level (users/columns/user): who they are, a row for each of their pages, and the
     * account's actions; one's own Profile has just the fields.
     */
    #[Locked]
    public $rows = true;

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

    public function mount($user, $rows = true, $section = null)
    {
        $this->user_id = $user->id;
        $this->rows = (bool) $rows;
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
     * The user, for someone who may edit them; not a deleted one.
     */
    protected function user()
    {
        $user = User::findOrFail($this->user_id);
        abort_if($user->isDeleted(), 404);
        $this->authorize('update', $user);

        return $user;
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
        if (in_array($property, self::ADMIN_FIELDS) && !$auth_user->isAdmin()) {
            $this->mount($user, $this->rows, $this->section);

            return;
        }
        $this->validateOnly($property);
        $this->checkLastAdmin($user, $property);
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

    /**
     * The last active admin stays one, and active: disabled and deleted admins manage nothing.
     */
    protected function checkLastAdmin(User $user, $property)
    {
        if (!in_array($property, ['role', 'disabled']) || !$user->isAdmin() || !$user->isActive()) {
            return;
        }
        $others = User::where('role', User::ROLE_ADMIN)->where('status', User::STATUS_ACTIVE)->where('id', '!=', $user->id)->count();
        if ($others) {
            return;
        }
        if ($property == 'role' && $this->role != User::ROLE_ADMIN) {
            $this->role = (string) User::ROLE_ADMIN;
            throw ValidationException::withMessages(['role' => __('Role of the only one administrator can not be changed.')]);
        }
        if ($property == 'disabled' && $this->disabled) {
            $this->disabled = false;
            throw ValidationException::withMessages(['disabled' => __('The only active administrator can not be disabled.')]);
        }
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
