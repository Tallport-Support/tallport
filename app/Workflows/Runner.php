<?php

namespace App\Workflows;

use App\Conversation;
use App\Thread;
use App\User;
use App\Workflow;

/**
 * Runs workflows: automatic ones when something happens to a conversation
 * (the events below) and as time passes (tallport:workflows, every 5
 * minutes), manual ones when a user runs them. Automatic actions are done
 * by the Workflow user (a robot); the conversation then says which workflow
 * ran. A workflow runs on a conversation at most max_executions times.
 */
class Runner
{
    const ROBOT_EMAIL = 'fsworkflow@example.org';

    /**
     * Events that run automatic workflows, and whether the person acting
     * (event argument) is skipped when it's the Workflow user.
     */
    const EVENTS = [
        'conversation.created_by_customer' => false,
        'conversation.created_by_user'     => true,
        'conversation.customer_replied'    => true,
        'conversation.user_replied'        => true,
        'conversation.note_added'          => true,
        'conversation.user_forwarded'      => false,
        'conversation.status_changed'      => true,
        'conversation.state_changed'       => true,
        'conversation.user_changed'        => true,
        'conversation.moved'               => false,
        'thread.opened'                    => false,
    ];

    /**
     * Conversation ID => workflow IDs running on it now (no loops).
     */
    protected static $running = [];

    protected static $last_threads = [];

    protected static $robot;

    public static function listen()
    {
        foreach (self::EVENTS as $event => $skip_robot) {
            \Eventy::addAction($event, function (...$args) use ($event, $skip_robot) {
                [$conversation, $actor] = self::eventSubjects($event, $args);
                if (!$conversation instanceof Conversation) {
                    return;
                }
                if ($skip_robot && $actor && $actor->email == self::ROBOT_EMAIL) {
                    return;
                }
                try {
                    self::runForEvent($conversation, $event);
                } catch (\Throwable $e) {
                    \Helper::logException($e, '[Workflows]');
                }
            }, 20, 5);
        }

        Actions::listen();

        // The line saying a workflow ran.
        \Eventy::addFilter('thread.action_text', function ($text, $thread, $conversation_number, $escape, $viewed_by_user = null) {
            if (!in_array($thread->action_type, [Thread::ACTION_TYPE_WORKFLOW_AUTOMATIC, Thread::ACTION_TYPE_WORKFLOW_MANUAL])) {
                return $text;
            }
            $workflow = Workflow::find($thread->getMeta(Actions::META_WORKFLOW));
            $name = $workflow ? $workflow->name : __('Deleted');
            if ($escape) {
                $name = e($name);
            }
            if ($workflow && $escape && Workflow::canEdit($viewed_by_user ?: auth()->user(), $workflow->mailbox)) {
                $name = '<a href="'.$workflow->url().'">'.$name.'</a>';
            } elseif ($escape) {
                $name = '<strong>'.$name.'</strong>';
            }
            if ($thread->action_type == Thread::ACTION_TYPE_WORKFLOW_AUTOMATIC) {
                return $conversation_number
                    ? __('Workflow :workflow was triggered for conversation #:conversation_number', ['workflow' => $name, 'conversation_number' => $conversation_number])
                    : __('Workflow :workflow was triggered', ['workflow' => $name]);
            }

            return $conversation_number
                ? __(':person ran the :workflow workflow for conversation #:conversation_number', ['workflow' => $name, 'conversation_number' => $conversation_number])
                : __(':person ran the :workflow workflow', ['workflow' => $name]);
        }, 20, 5);

        // A deleted mailbox's workflows go; workflows tied to a deleted user stop.
        \Eventy::addAction('mailbox.before_delete', function ($mailbox) {
            $ids = Workflow::where('mailbox_id', $mailbox->id)->pluck('id');
            \DB::table(Workflow::CONVERSATIONS_TABLE)->whereIn('workflow_id', $ids)->delete();
            Workflow::whereIn('id', $ids)->delete();
        }, 20, 1);
        \Eventy::addAction('user.deleted', function ($user) {
            foreach (Workflow::where('active', true)->get() as $workflow) {
                if (self::usesUser($workflow, $user->id)) {
                    $workflow->active = false;
                    $workflow->save();
                }
            }
        }, 20, 1);

        // Manual workflows in the conversation's menu.
        \Eventy::addAction('conversation.append_action_buttons', function ($conversation, $mailbox) {
            $workflows = Workflow::activeFor($conversation->mailbox_id, Workflow::TYPE_MANUAL);
            if (count($workflows)) {
                echo view('workflows/partials/run_menu', ['workflows' => $workflows])->render();
            }
        }, 20, 2);

        \Eventy::addAction('thread.meta', function ($thread) {
            if ($thread->type != Thread::TYPE_LINEITEM && ($workflow_id = $thread->getMeta(Actions::META_WORKFLOW))) {
                echo view('workflows/partials/thread_meta', ['workflow' => Workflow::find($workflow_id)])->render();
            }
        }, 20, 1);
    }

    /**
     * Whether a workflow's conditions or actions name a user.
     */
    public static function usesUser(Workflow $workflow, $user_id)
    {
        foreach (array_merge($workflow->getConditions(), $workflow->getActions()) as $group) {
            foreach ($group as $row) {
                if (in_array($row['type'], ['user', 'user_action', 'assign', 'notification'])
                    && in_array((string) $user_id, array_map('strval', (array) ($row['value'] ?? [])), true)
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The conversation and who acted, from an event's arguments.
     */
    protected static function eventSubjects($event, array $args)
    {
        switch ($event) {
            case 'conversation.user_forwarded':
                // The new conversation.
                return [$args[2] ?? null, $args[1]->created_by_user ?? null];
            case 'thread.opened':
                return [$args[1] ?? null, null];
            case 'conversation.created_by_user':
            case 'conversation.customer_replied':
            case 'conversation.user_replied':
            case 'conversation.note_added':
                $thread = $args[1] ?? null;

                return [$args[0] ?? null, $thread && $thread->created_by_user_id ? User::find($thread->created_by_user_id) : null];
            case 'conversation.created_by_customer':
                return [$args[0] ?? null, null];
            default:
                return [$args[0] ?? null, ($args[1] ?? null) instanceof User ? $args[1] : null];
        }
    }

    /**
     * The Workflow user, who does automatic actions.
     */
    public static function robot()
    {
        if (self::$robot && self::$robot->exists) {
            return self::$robot;
        }
        $robot = User::where('email', self::ROBOT_EMAIL)->first();
        if (!$robot) {
            $robot = new User();
            $robot->email = self::ROBOT_EMAIL;
            $robot->password = \Hash::make(\Str::random(40));
        }
        $robot->first_name = 'Workflow';
        $robot->last_name = '';
        $robot->type = User::TYPE_ROBOT;
        $robot->status = User::STATUS_DELETED;
        if ($robot->isDirty()) {
            $robot->save();
        }

        return self::$robot = $robot;
    }

    /**
     * The conversation's last published thread (for this run).
     */
    public static function lastThread(Conversation $conversation)
    {
        if (!array_key_exists($conversation->id, self::$last_threads)) {
            self::$last_threads[$conversation->id] = $conversation->getLastThread();
        }

        return self::$last_threads[$conversation->id];
    }

    /**
     * Run the automatic workflows that apply to what happened.
     */
    public static function runForEvent(Conversation $conversation, $trigger)
    {
        $outer = !self::$running;
        try {
            foreach (Workflow::activeFor($conversation->mailbox_id) as $workflow) {
                if (!self::canRunAgain($workflow, $conversation)) {
                    continue;
                }
                if (!Conditions::check($workflow, $conversation, $trigger)) {
                    continue;
                }
                $result = self::run($workflow, $conversation);
                if (in_array($result, ['stop', 'deleted'], true)) {
                    break;
                }
            }
        } finally {
            if ($outer) {
                self::$last_threads = [];
            }
        }
    }

    /**
     * Whether an automatic workflow may run on the conversation (again).
     */
    public static function canRunAgain(Workflow $workflow, Conversation $conversation)
    {
        if (!empty(self::$running[$conversation->id][$workflow->id])) {
            return false;
        }
        $counter = (int) \DB::table(Workflow::CONVERSATIONS_TABLE)
            ->where('conversation_id', $conversation->id)->where('workflow_id', $workflow->id)->value('counter');

        return $counter < max(1, (int) $workflow->max_executions);
    }

    /**
     * Do a workflow's actions on a conversation. $user: who ran a manual
     * workflow. Returns 'stop' or 'deleted' when later workflows mustn't
     * run, else whether something was done.
     */
    public static function run(Workflow $workflow, Conversation $conversation, ?User $user = null)
    {
        $robot = self::robot();
        // Counted first: an action that triggers workflows doesn't run this one again.
        if ($workflow->isAutomatic()) {
            self::count($workflow, $conversation);
        }
        self::$running[$conversation->id][$workflow->id] = true;

        $done = false;
        $result = null;
        try {
            foreach ($workflow->getActions() as $group) {
                foreach ($group as $row) {
                    $outcome = Actions::perform($row, $conversation, $workflow, $robot, $user);
                    if ($outcome === 'deleted') {
                        return 'deleted';
                    }
                    if ($outcome === 'stop') {
                        $result = 'stop';
                    } elseif ($outcome) {
                        $done = true;
                    }
                }
                $conversation->refresh();
            }
        } finally {
            unset(self::$running[$conversation->id][$workflow->id]);
            if (empty(self::$running[$conversation->id])) {
                unset(self::$running[$conversation->id]);
            }
        }

        if ($done) {
            self::addLine($workflow, $conversation, $user ?: $robot);
        }

        return $result ?? $done;
    }

    protected static function count(Workflow $workflow, Conversation $conversation)
    {
        $updated = \DB::table(Workflow::CONVERSATIONS_TABLE)
            ->where('conversation_id', $conversation->id)->where('workflow_id', $workflow->id)
            ->increment('counter');
        if (!$updated) {
            \DB::table(Workflow::CONVERSATIONS_TABLE)->insertOrIgnore([
                'conversation_id' => $conversation->id, 'workflow_id' => $workflow->id, 'counter' => 1,
            ]);
        }
    }

    protected static function addLine(Workflow $workflow, Conversation $conversation, User $user)
    {
        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->user_id = $conversation->user_id;
        $thread->type = Thread::TYPE_LINEITEM;
        $thread->state = Thread::STATE_PUBLISHED;
        $thread->status = Thread::STATUS_NOCHANGE;
        $thread->action_type = $workflow->isAutomatic() ? Thread::ACTION_TYPE_WORKFLOW_AUTOMATIC : Thread::ACTION_TYPE_WORKFLOW_MANUAL;
        $thread->source_via = Thread::PERSON_USER;
        $thread->source_type = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id = $conversation->customer_id;
        $thread->created_by_user_id = $user->id;
        $thread->setMeta(Actions::META_WORKFLOW, $workflow->id);
        $thread->save();
    }

    /**
     * Run a manual workflow on conversations, as a user. Returns how many
     * it ran on.
     */
    public static function runManual(Workflow $workflow, $conversations, User $user)
    {
        $count = 0;
        foreach ($conversations as $conversation) {
            if ($workflow->mailbox_id && $workflow->mailbox_id != $conversation->mailbox_id) {
                continue;
            }
            self::run($workflow, $conversation, $user);
            $count++;
        }

        return $count;
    }

    /**
     * Automatic workflows whose conditions depend on time: run on the
     * conversations that meet them now. Returns how many runs.
     */
    public static function processDue($seconds = 240)
    {
        $started = microtime(true);
        $runs = 0;
        $stopped = [];
        $workflows = Workflow::where('active', true)->where('type', Workflow::TYPE_AUTOMATIC)
            ->orderByRaw('mailbox_id IS NOT NULL')->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn ($workflow) => $workflow->hasDateConditions());
        foreach ($workflows as $workflow) {
            $runs += self::processWorkflow($workflow, $stopped, $started + $seconds);
            if (microtime(true) > $started + $seconds) {
                break;
            }
        }

        return $runs;
    }

    /**
     * Run an automatic workflow on every conversation meeting its
     * conditions (without an event). $stopped: conversations a workflow
     * stopped others on.
     */
    public static function processWorkflow(Workflow $workflow, array &$stopped = [], $until = null)
    {
        $runs = 0;
        $last_id = 0;
        do {
            $conversations = self::candidates($workflow)->where('conversations.id', '>', $last_id)
                ->orderBy('conversations.id')->limit(500)->get();
            foreach ($conversations as $conversation) {
                $last_id = $conversation->id;
                if (isset($stopped[$conversation->id]) || !self::canRunAgain($workflow, $conversation)
                    || !Conditions::check($workflow, $conversation)
                ) {
                    continue;
                }
                $result = self::run($workflow, $conversation);
                $runs++;
                if (in_array($result, ['stop', 'deleted'], true)) {
                    $stopped[$conversation->id] = true;
                }
            }
            self::$last_threads = [];
        } while (count($conversations) && (!$until || microtime(true) < $until));

        return $runs;
    }

    /**
     * Conversations a workflow could run on: in its mailbox, not run on
     * (enough), new since it was made (unless for previous ones too), and
     * narrowed by conditions every one must meet.
     */
    protected static function candidates(Workflow $workflow)
    {
        $query = Conversation::select('conversations.*')
            ->leftJoin(Workflow::CONVERSATIONS_TABLE.' as cw', function ($join) use ($workflow) {
                $join->on('cw.conversation_id', '=', 'conversations.id')->where('cw.workflow_id', $workflow->id);
            })
            ->where(function ($query) use ($workflow) {
                $query->whereNull('cw.counter')->orWhere('cw.counter', '<', max(1, (int) $workflow->max_executions));
            })
            ->where('conversations.state', '!=', Conversation::STATE_DRAFT);
        if ($workflow->mailbox_id) {
            $query->where('conversations.mailbox_id', $workflow->mailbox_id);
        }
        if (!$workflow->apply_to_prev) {
            $query->where('conversations.created_at', '>=', $workflow->created_at);
        }

        $deleted = false;
        foreach ($workflow->getConditions() as $group) {
            foreach ($group as $row) {
                $deleted = $deleted || ($row['type'] == 'state' && ($row['operator'] ?? '') == 'equal' && (int) ($row['value'] ?? 0) == Conversation::STATE_DELETED);
            }
            if (count($group) != 1) {
                continue;
            }
            $row = $group[0];
            $value = $row['value'] ?? null;
            $not = ($row['operator'] ?? '') == 'not_equal';
            switch ($row['type']) {
                case 'status':
                case 'type':
                case 'state':
                    $query->where('conversations.'.$row['type'], $not ? '!=' : '=', (int) $value);
                    break;
                case 'user':
                    if ((int) $value == -1) {
                        $not ? $query->whereNotNull('conversations.user_id') : $query->whereNull('conversations.user_id');
                    } elseif ($not) {
                        // Unassigned conversations aren't assigned to X either.
                        $query->where(function ($query) use ($value) {
                            $query->where('conversations.user_id', '!=', (int) $value)->orWhereNull('conversations.user_id');
                        });
                    } else {
                        $query->where('conversations.user_id', (int) $value);
                    }
                    break;
                case 'waiting':
                    $seconds = Conditions::seconds($value);
                    if ($seconds) {
                        $query->whereIn('conversations.status', [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING])
                            ->where('conversations.last_reply_from', '!=', Conversation::PERSON_USER)
                            ->where('conversations.last_reply_at', ($row['operator'] ?? '') == 'not_longer' ? '>' : '<', now()->subSeconds($seconds));
                    }
                    break;
                case 'created':
                    $seconds = Conditions::seconds($value);
                    if ($seconds) {
                        $query->where('conversations.created_at', ($row['operator'] ?? '') == 'not_in_last' ? '<' : '>', now()->subSeconds($seconds));
                    }
                    break;
            }
        }
        if (!$deleted) {
            $query->where('conversations.state', Conversation::STATE_PUBLISHED);
        }

        return $query;
    }
}
