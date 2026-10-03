<?php

namespace App\Http\Controllers;

use App\Conversation;
use App\Mailbox;
use App\User;
use App\Workflow;
use App\Workflows\Actions;
use App\Workflows\Conditions;
use App\Workflows\Runner;
use Illuminate\Http\Request;

/**
 * Workflows: of a mailbox (Mailbox Settings » Workflows, for admins and
 * users allowed to manage workflows) and of all mailboxes (Manage »
 * Workflows, admins). Manual workflows are run from a conversation's menu.
 */
class WorkflowsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index($mailbox_id = null)
    {
        $mailbox = $this->editable($mailbox_id);
        $workflows = Workflow::where('mailbox_id', $mailbox ? $mailbox->id : null)->orderBy('sort_order')->orderBy('id')->get();

        return view('workflows/index', [
            'mailbox'   => $mailbox,
            'automatic' => $workflows->where('type', Workflow::TYPE_AUTOMATIC),
            'manual'    => $workflows->where('type', Workflow::TYPE_MANUAL),
            'global'    => $mailbox ? Workflow::whereNull('mailbox_id')->where('active', true)->count() : 0,
        ]);
    }

    public function globalIndex()
    {
        return $this->index(null);
    }

    public function edit($mailbox_id = null, $id = null)
    {
        $mailbox = $this->editable($mailbox_id);
        $workflow = $id ? $this->workflow($mailbox, $id) : new Workflow();
        if (!$workflow->exists) {
            $workflow->type = Workflow::TYPE_AUTOMATIC;
            $workflow->max_executions = 1;
            $workflow->active = true;
        }

        return view('workflows/edit', [
            'mailbox'  => $mailbox,
            'workflow' => $workflow,
            'config'   => [
                'conditions' => self::forEditor(Conditions::config($mailbox ? $mailbox->id : null)),
                'actions'    => self::forEditor(Actions::config($mailbox ? $mailbox->id : null)),
            ],
        ]);
    }

    public function globalEdit($id = null)
    {
        return $this->edit(null, $id);
    }

    public function save(Request $request, $mailbox_id = null)
    {
        $mailbox = $this->editable($mailbox_id);
        $workflow = $request->workflow_id ? $this->workflow($mailbox, $request->workflow_id) : new Workflow();

        $request->validate([
            'name'           => 'required|string|max:75',
            'type'           => 'required|in:'.Workflow::TYPE_AUTOMATIC.','.Workflow::TYPE_MANUAL,
            'max_executions' => 'nullable|integer|min:1|max:1000000',
        ]);
        $name = trim(strip_tags($request->name));
        if (Workflow::where('mailbox_id', $mailbox ? $mailbox->id : null)->where('name', $name)->where('id', '!=', (int) $workflow->id)->exists()) {
            return back()->withInput()->withErrors(['name' => __('A workflow with this name already exists.')]);
        }

        $type = (int) $request->type;
        $conditions = $type == Workflow::TYPE_AUTOMATIC ? Workflow::groups((string) $request->conditions) : [];
        $actions = Workflow::groups((string) $request->actions);
        $errors = self::validateWorkflow($type, $conditions, $actions, $mailbox);
        if ($errors) {
            return back()->withInput()->withErrors($errors);
        }

        $workflow->mailbox_id = $mailbox ? $mailbox->id : null;
        $workflow->name = $name;
        $workflow->type = $type;
        $workflow->setConditions($conditions);
        $workflow->setActions($actions);
        $workflow->complete = true;
        $workflow->active = (bool) $request->active;
        $workflow->max_executions = max(1, (int) $request->max_executions);
        $workflow->apply_to_prev = $type == Workflow::TYPE_AUTOMATIC && $request->apply_to_prev;
        if (!$workflow->exists) {
            $workflow->sort_order = (int) Workflow::where('mailbox_id', $workflow->mailbox_id)->max('sort_order') + 1;
        }
        $workflow->save();

        if ($workflow->active && $workflow->apply_to_prev) {
            \App\Jobs\ApplyWorkflow::dispatch($workflow->id)->onQueue('default');
            \Session::flash('flash_success_floating', __('Workflow saved: it runs on existing conversations in the background.'));
        } else {
            \Session::flash('flash_success_floating', __('Workflow saved'));
        }

        return redirect()->away($workflow->url());
    }

    public function globalSave(Request $request)
    {
        return $this->save($request, null);
    }

    public function ajax(Request $request)
    {
        $response = ['status' => 'error', 'msg' => ''];
        $user = auth()->user();

        switch ($request->action) {
            case 'delete':
                $workflow = Workflow::find($request->workflow_id);
                if (!$workflow || !Workflow::canEdit($user, $workflow->mailbox) || ($workflow->isGlobal() && !$user->isAdmin())) {
                    $response['msg'] = __('Not enough permissions');
                    break;
                }
                \DB::table(Workflow::CONVERSATIONS_TABLE)->where('workflow_id', $workflow->id)->delete();
                $workflow->delete();
                \Session::flash('flash_success_floating', __('Workflow deleted'));
                $response['status'] = 'success';
                break;

            case 'sort':
                $mailbox = $request->mailbox_id ? Mailbox::find($request->mailbox_id) : null;
                if (($request->mailbox_id && !$mailbox) || !Workflow::canEdit($user, $mailbox) || (!$mailbox && !$user->isAdmin())) {
                    $response['msg'] = __('Not enough permissions');
                    break;
                }
                foreach (array_values((array) $request->workflows) as $i => $workflow_id) {
                    Workflow::where('id', (int) $workflow_id)->where('mailbox_id', $mailbox ? $mailbox->id : null)->update(['sort_order' => $i + 1]);
                }
                $response['status'] = 'success';
                break;

            case 'run':
                $workflow = Workflow::find($request->workflow_id);
                $conversation = Conversation::find($request->conversation_id);
                if (!$workflow || !$conversation || $workflow->isAutomatic() || !$workflow->active
                    || ($workflow->mailbox_id && $workflow->mailbox_id != $conversation->mailbox_id)
                    || !$user->can('view', $conversation)
                ) {
                    $response['msg'] = __('Not enough permissions');
                    break;
                }
                Runner::runManual($workflow, [$conversation], $user);
                \Session::flash('flash_success_floating', __('Workflow :workflow has run', ['workflow' => $workflow->name]));
                $conversation = Conversation::find($conversation->id);
                if (!$conversation || !$user->can('view', $conversation)) {
                    $response['redirect_url'] = route('mailboxes.view', ['id' => $workflow->mailbox_id ?: $request->mailbox_id]);
                }
                $response['status'] = 'success';
                break;

            default:
                $response['msg'] = 'Unknown action';
                break;
        }

        return \Response::json($response);
    }

    /**
     * Conditions or actions for the editor: choices as [value, title] pairs
     * (JavaScript would reorder numeric keys); none: [].
     */
    public static function forEditor(array $config)
    {
        foreach ($config as $group_key => $group) {
            foreach ((array) ($group['items'] ?? []) as $key => $item) {
                if (array_key_exists('values', $item)) {
                    $options = [];
                    foreach ((array) $item['values'] as $value => $title) {
                        $options[] = [(string) $value, (string) $title];
                    }
                    $config[$group_key]['items'][$key]['values'] = $options;
                }
            }
        }

        return $config;
    }

    /**
     * Errors by field ('conditions', 'actions'), or [].
     */
    public static function validateWorkflow($type, array $conditions, array $actions, ?Mailbox $mailbox)
    {
        $errors = [];
        $mailbox_id = $mailbox ? $mailbox->id : null;
        if ($type == Workflow::TYPE_AUTOMATIC && !$conditions) {
            $errors['conditions'] = __('Add a condition.');
        }
        foreach ($conditions as $group) {
            foreach ($group as $row) {
                $item = Conditions::item($row['type'], $mailbox_id);
                $value = $row['value'] ?? null;
                if (!$item || empty($row['operator']) || !isset($item['operators'][$row['operator']])) {
                    $errors['conditions'] = __('Complete or remove the incomplete conditions.');
                } elseif (($item['values_type'] ?? '') == 'date' && !Conditions::seconds((array) $value)) {
                    $errors['conditions'] = __('Enter a time for the date conditions.');
                } elseif (!isset($item['values']) && !in_array($row['operator'], ['customer', 'note']) && trim((string) (is_scalar($value) ? $value : '')) === '' && ($item['values_type'] ?? '') != 'date') {
                    $errors['conditions'] = __('Complete or remove the incomplete conditions.');
                } elseif ($row['operator'] == 'regex' && @preg_match((string) $value, '') === false) {
                    $errors['conditions'] = __('Not a valid regex pattern: :pattern', ['pattern' => $value]);
                } elseif (\Eventy::filter('workflow.validate_condition', false, $row, null)) {
                    $errors['conditions'] = __('Complete or remove the incomplete conditions.');
                }
            }
        }

        if (!$actions) {
            $errors['actions'] = __('Add an action.');
        }
        foreach ($actions as $group) {
            foreach ($group as $row) {
                $item = Actions::item($row['type'], $mailbox_id);
                $value = $row['value'] ?? null;
                $email = Actions::email($value);
                if (!$item) {
                    $errors['actions'] = __('Complete or remove the incomplete actions.');
                } elseif (in_array($row['type'], Actions::EMAIL_TYPES) && trim(strip_tags((string) ($email['body'] ?? ''), '<img>')) === '') {
                    $errors['actions'] = __('Write the text of the emails and notes.');
                } elseif ($row['type'] == 'forward' && !Conversation::sanitizeEmails($email['to'] ?? '')) {
                    $errors['actions'] = __('Enter the address to forward to.');
                } elseif ($row['type'] == 'assign' && !in_array((int) $value, [-1, Workflow::ASSIGNEE_CURRENT]) && !User::nonDeleted()->where('id', (int) $value)->exists()) {
                    $errors['actions'] = __('Complete or remove the incomplete actions.');
                } elseif ($row['type'] == 'assign' && (int) $value == Workflow::ASSIGNEE_CURRENT && $type == Workflow::TYPE_AUTOMATIC) {
                    $errors['actions'] = __('Only manual workflows can assign to the user running them.');
                } elseif (!empty($item['values']) && $row['type'] != 'notification' && !in_array((string) $value, array_map('strval', array_keys($item['values'])), true)) {
                    $errors['actions'] = __('Complete or remove the incomplete actions.');
                } elseif ($row['type'] == 'notification' && !array_filter((array) $value)) {
                    $errors['actions'] = __('Complete or remove the incomplete actions.');
                } elseif (($item['values_type'] ?? '') == 'text' && trim((string) $value) === '') {
                    $errors['actions'] = __('Complete or remove the incomplete actions.');
                } elseif (\Eventy::filter('workflow.validate_action', false, $row, null)) {
                    $errors['actions'] = __('Complete or remove the incomplete actions.');
                }
            }
        }

        return $errors;
    }

    /**
     * The mailbox whose workflows the user edits (null: all mailboxes, admins).
     */
    protected function editable($mailbox_id)
    {
        $user = auth()->user();
        if (!$mailbox_id) {
            if (!$user->isAdmin()) {
                abort(403);
            }

            return null;
        }
        $mailbox = Mailbox::findOrFail($mailbox_id);
        if (!Workflow::canEdit($user, $mailbox)) {
            abort(403);
        }

        return $mailbox;
    }

    protected function workflow(?Mailbox $mailbox, $id)
    {
        return Workflow::where('mailbox_id', $mailbox ? $mailbox->id : null)->findOrFail($id);
    }
}
