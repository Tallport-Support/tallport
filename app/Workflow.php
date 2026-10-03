<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * A workflow: automatic ones run their actions on conversations that meet
 * their conditions (when something happens, or as time passes); manual ones
 * when a user runs them. Of a mailbox, or of all mailboxes (no mailbox_id).
 *
 * conditions: AND groups of OR rows, [[{type, operator, value}, ...], ...].
 * actions: groups of one row each, [[{type, value}], ...].
 */
class Workflow extends Model
{
    const TYPE_AUTOMATIC = 1;
    const TYPE_MANUAL = 2;

    /**
     * Assign to: the user running a manual workflow.
     */
    const ASSIGNEE_CURRENT = -10;

    /**
     * Conversations a workflow ran on (and how often).
     */
    const CONVERSATIONS_TABLE = 'conversation_workflow';

    protected $casts = [
        'apply_to_prev'  => 'boolean',
        'complete'       => 'boolean',
        'active'         => 'boolean',
        'max_executions' => 'integer',
    ];

    public function mailbox()
    {
        return $this->belongsTo('App\Mailbox');
    }

    public function setNameAttribute($name)
    {
        $this->attributes['name'] = mb_substr(trim(strip_tags((string) $name)), 0, 75);
    }

    public function isAutomatic()
    {
        return $this->type == self::TYPE_AUTOMATIC;
    }

    public function isGlobal()
    {
        return !$this->mailbox_id;
    }

    /**
     * The conditions as AND groups of OR rows (lists, whatever was stored).
     */
    public function getConditions()
    {
        return self::groups($this->conditions);
    }

    public function getActions()
    {
        return self::groups($this->actions);
    }

    public function setConditions(array $groups)
    {
        $this->conditions = json_encode(self::groups($groups), JSON_UNESCAPED_UNICODE);
    }

    public function setActions(array $groups)
    {
        $this->actions = json_encode(self::groups($groups), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Groups of rows as lists: stored JSON can have objects with numeric keys.
     */
    public static function groups($groups)
    {
        if (is_string($groups)) {
            $groups = json_decode($groups, true);
        }
        $result = [];
        foreach ((array) $groups as $group) {
            $rows = [];
            foreach ((array) $group as $row) {
                if (is_array($row) && !empty($row['type'])) {
                    $rows[] = $row;
                }
            }
            if ($rows) {
                $result[] = $rows;
            }
        }

        return $result;
    }

    /**
     * Whether a condition depends on time passing (checked by tallport:workflows).
     */
    public function hasDateConditions()
    {
        foreach ($this->getConditions() as $group) {
            foreach ($group as $row) {
                if (in_array($row['type'], \App\Workflows\Conditions::DATE_TYPES)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Active workflows that apply to a mailbox: those of all mailboxes
     * first, each in its order.
     */
    public static function activeFor($mailbox_id, $type = self::TYPE_AUTOMATIC)
    {
        return self::where('active', true)
            ->where('type', $type)
            ->where(function ($query) use ($mailbox_id) {
                $query->whereNull('mailbox_id')->orWhere('mailbox_id', $mailbox_id);
            })
            ->orderByRaw('mailbox_id IS NOT NULL')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * How many conversations it ran on.
     */
    public function conversationsCount()
    {
        return \DB::table(self::CONVERSATIONS_TABLE)->where('workflow_id', $this->id)->count();
    }

    public function url()
    {
        return $this->mailbox_id
            ? route('mailboxes.workflows.update', ['mailbox_id' => $this->mailbox_id, 'id' => $this->id])
            : route('workflows.update', ['id' => $this->id]);
    }

    /**
     * Users allowed to edit a mailbox's workflows (all mailboxes': admins).
     */
    public static function canEdit(?User $user, ?Mailbox $mailbox = null)
    {
        if (!$user) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        return $mailbox && $user->hasPermission(User::PERM_EDIT_WORKFLOWS) && $mailbox->userHasAccess($user->id);
    }
}
