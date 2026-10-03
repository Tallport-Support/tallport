<?php

namespace App\Http\Controllers;

use App\Attachment;
use App\Conversation;
use App\Mailbox;
use App\SavedReply;
use Illuminate\Http\Request;

/**
 * Saved replies: Mailbox Settings » Saved Replies, and the editor's
 * saved replies menu.
 */
class SavedRepliesController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index($id)
    {
        $mailbox = $this->manageable($id);

        return view('mailboxes/saved_replies', [
            'mailbox' => $mailbox,
            'tree'    => SavedReply::tree(SavedReply::ofMailbox($mailbox->id)),
        ]);
    }

    public function edit($id, $saved_reply_id = null)
    {
        $mailbox = $this->manageable($id);
        $saved_reply = $saved_reply_id ? $this->savedReply($mailbox, $saved_reply_id) : new SavedReply();

        return view('mailboxes/saved_reply', [
            'mailbox'     => $mailbox,
            'saved_reply' => $saved_reply,
            'parents'     => $this->possibleParents($mailbox, $saved_reply),
            'attachments' => Attachment::whereIn('id', (array) $saved_reply->attachments)->get(),
        ]);
    }

    public function save(Request $request, $id)
    {
        $mailbox = $this->manageable($id);
        $saved_reply = $request->saved_reply_id ? $this->savedReply($mailbox, $request->saved_reply_id) : new SavedReply();

        $request->validate([
            'name'          => 'required|string|max:'.SavedReply::NAME_MAX_LENGTH,
            'text'          => 'nullable|string',
            'files.*'       => 'file',
        ]);
        $name = trim($request->name);
        $exists = SavedReply::where('mailbox_id', $mailbox->id)->where('name', $name)->where('id', '!=', (int) $saved_reply->id)->exists();
        if ($exists) {
            return back()->withInput()->withErrors(['name' => __('A saved reply with this name already exists in this mailbox.')]);
        }
        $parent_id = (int) $request->parent_saved_reply_id ?: null;
        if ($parent_id && !$this->possibleParents($mailbox, $saved_reply)->contains('id', $parent_id)) {
            $parent_id = null;
        }

        if (!$saved_reply->exists) {
            $saved_reply->mailbox_id = $mailbox->id;
            $saved_reply->user_id = auth()->user()->id;
            $saved_reply->sort_order = (int) SavedReply::where('mailbox_id', $mailbox->id)->max('sort_order') + 1;
        }
        $saved_reply->name = $name;
        $saved_reply->text = \Helper::stripDangerousTags((string) $request->text);
        $saved_reply->parent_saved_reply_id = $parent_id;
        $saved_reply->global = (bool) $request->global;
        $saved_reply->auto_load = (bool) $request->auto_load;

        // Files: removed ones deleted, new ones added.
        $attachment_ids = array_map('intval', (array) $saved_reply->attachments);
        $removed = array_intersect($attachment_ids, array_map('intval', (array) $request->remove_attachments));
        if ($removed) {
            Attachment::deleteForever(Attachment::whereIn('id', $removed)->get());
            $attachment_ids = array_values(array_diff($attachment_ids, $removed));
        }
        foreach ((array) $request->file('files') as $file) {
            $attachment = Attachment::create($file->getClientOriginalName(), $file->getMimeType(), null, null, $file, false, null, auth()->user()->id);
            if ($attachment) {
                $attachment_ids[] = $attachment->id;
            }
        }
        $saved_reply->attachments = $attachment_ids ?: null;
        $saved_reply->save();

        // One default reply template per mailbox.
        if ($saved_reply->auto_load) {
            SavedReply::where('mailbox_id', $mailbox->id)->where('id', '!=', $saved_reply->id)->update(['auto_load' => false]);
        }

        \Session::flash('flash_success_floating', __('Saved'));

        return redirect()->route('mailboxes.saved_replies', ['id' => $mailbox->id]);
    }

    public function delete($id, $saved_reply_id)
    {
        $mailbox = $this->manageable($id);
        $this->savedReply($mailbox, $saved_reply_id)->deleteWithAttachments();
        \Session::flash('flash_success_floating', __('Saved reply deleted'));

        return redirect()->route('mailboxes.saved_replies', ['id' => $mailbox->id]);
    }

    public function ajax(Request $request)
    {
        $user = auth()->user();
        $response = ['status' => 'error', 'msg' => ''];

        switch ($request->action) {
            // The text (variables filled in) and copies of the files.
            case 'get':
            case 'template':
                $mailbox = Mailbox::find($request->mailbox_id);
                if (!$mailbox || !in_array($mailbox->id, $user->mailboxesIdsCanView())) {
                    $response['msg'] = __('Not enough permissions');
                    break;
                }
                $saved_reply = $request->action == 'template'
                    ? SavedReply::template($mailbox->id)
                    : SavedReply::find($request->saved_reply_id);
                if (!$saved_reply || !$saved_reply->canUse($user)) {
                    $response['msg'] = $request->action == 'template' ? '' : __('Saved reply not found');
                    break;
                }
                $conversation = $request->conversation_id ? Conversation::find($request->conversation_id) : null;
                if ($conversation && !$user->can('view', $conversation)) {
                    $conversation = null;
                }
                $response['id'] = $saved_reply->id;
                $response['text'] = $saved_reply->render($mailbox, $user, $conversation);
                $response['attachments'] = $saved_reply->copyAttachments();
                $response['status'] = 'success';
                break;

            // The current reply as a new saved reply.
            case 'save_from_reply':
                $mailbox = Mailbox::find($request->mailbox_id);
                if (!$mailbox || !SavedReply::canManage($user, $mailbox)) {
                    $response['msg'] = __('Not enough permissions');
                    break;
                }
                $name = mb_substr(trim((string) $request->name), 0, SavedReply::NAME_MAX_LENGTH);
                if ($name === '') {
                    $response['msg'] = __('Enter a name');
                    break;
                }
                if (SavedReply::where('mailbox_id', $mailbox->id)->where('name', $name)->exists()) {
                    $response['msg'] = __('A saved reply with this name already exists in this mailbox.');
                    break;
                }
                $saved_reply = new SavedReply();
                $saved_reply->mailbox_id = $mailbox->id;
                $saved_reply->user_id = $user->id;
                $saved_reply->name = $name;
                $saved_reply->text = \Helper::stripDangerousTags((string) $request->text);
                $saved_reply->sort_order = (int) SavedReply::where('mailbox_id', $mailbox->id)->max('sort_order') + 1;
                $saved_reply->save();
                $response['id'] = $saved_reply->id;
                $response['name'] = $saved_reply->name;
                $response['msg_success'] = __('Saved');
                $response['status'] = 'success';
                break;

            // New order of saved replies at one level.
            case 'sort':
                $mailbox = Mailbox::find($request->mailbox_id);
                if (!$mailbox || !SavedReply::canManage($user, $mailbox)) {
                    $response['msg'] = __('Not enough permissions');
                    break;
                }
                foreach (array_values((array) $request->saved_replies) as $i => $saved_reply_id) {
                    SavedReply::where('mailbox_id', $mailbox->id)->where('id', $saved_reply_id)->update(['sort_order' => $i + 1]);
                }
                $response['status'] = 'success';
                break;
        }

        if ($response['status'] == 'error' && $response['msg'] === '' && $request->action != 'template') {
            $response['msg'] = __('Unknown error occurred');
        }

        return \Response::json($response);
    }

    /**
     * A mailbox whose saved replies the user may manage.
     */
    protected function manageable($id)
    {
        $mailbox = Mailbox::findOrFail($id);
        if (!SavedReply::canManage(auth()->user(), $mailbox)) {
            abort(403);
        }

        return $mailbox;
    }

    protected function savedReply(Mailbox $mailbox, $saved_reply_id)
    {
        return SavedReply::where('mailbox_id', $mailbox->id)->findOrFail($saved_reply_id);
    }

    /**
     * Saved replies it can be put under: not itself or what's under it.
     */
    protected function possibleParents(Mailbox $mailbox, SavedReply $saved_reply)
    {
        $replies = SavedReply::ofMailbox($mailbox->id);
        if (!$saved_reply->exists) {
            return $replies;
        }
        $excluded = [$saved_reply->id];
        do {
            $more = $replies->whereIn('parent_saved_reply_id', $excluded)->pluck('id')->diff($excluded);
            $excluded = array_merge($excluded, $more->all());
        } while ($more->isNotEmpty());

        return $replies->whereNotIn('id', $excluded)->values();
    }
}
