<?php

namespace App\Http\Controllers;

use App\Attachment;
use App\Thread;
use App\User;
use Illuminate\Http\Request;

/**
 * Attachments of conversations: deleting one, all of a message as a zip,
 * an attached email shown as a page.
 */
class AttachmentsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Whether a user may delete attachments: as conversations.
     */
    public static function canDelete(User $user)
    {
        return $user->isAdmin() || $user->hasPermission(User::PERM_DELETE_CONVERSATIONS);
    }

    /**
     * POST: delete an attachment; the conversation gets a line saying so.
     */
    public function delete($id)
    {
        $user = auth()->user();
        $attachment = Attachment::find($id);
        $thread = $attachment ? $attachment->thread : null;
        if (!$thread || !$thread->conversation) {
            return \Response::json(['status' => 'error', 'msg' => __('Attachment not found')]);
        }
        $conversation = $thread->conversation;
        if (!$user->can('view', $conversation) || !self::canDelete($user)) {
            return \Response::json(['status' => 'error', 'msg' => __('Not enough permissions')]);
        }
        $file_name = $attachment->file_name;
        Attachment::deleteAttachments([$attachment]);

        $line = new Thread();
        $line->conversation_id = $conversation->id;
        $line->user_id = $conversation->user_id;
        $line->type = Thread::TYPE_LINEITEM;
        $line->state = Thread::STATE_PUBLISHED;
        $line->status = Thread::STATUS_NOCHANGE;
        $line->action_type = Thread::ACTION_TYPE_ATTACHMENT_DELETED;
        $line->action_data = mb_substr($file_name, 0, 255);
        $line->source_via = Thread::PERSON_USER;
        $line->source_type = Thread::SOURCE_TYPE_WEB;
        $line->customer_id = $conversation->customer_id;
        $line->created_by_user_id = $user->id;
        $line->save();

        return \Response::json(['status' => 'success']);
    }

    /**
     * The attachments of a message as one zip, made now.
     */
    public function download($thread_id)
    {
        $thread = Thread::findOrFail($thread_id);
        if (!$thread->conversation || !auth()->user()->can('view', $thread->conversation)) {
            abort(403);
        }
        $attachments = $thread->attachments;
        if (!count($attachments)) {
            abort(404);
        }

        $path = \Helper::getTempFileName();
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $names = [];
        foreach ($attachments as $attachment) {
            $content = $attachment->getFileContents();
            if ($content === null || $content === false) {
                continue;
            }
            // file.pdf, 2_file.pdf, ...
            $name = $attachment->file_name;
            for ($i = 2; isset($names[strtolower($name)]); $i++) {
                $name = $i.'_'.$attachment->file_name;
            }
            $names[strtolower($name)] = true;
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return response()->download($path, 'attachments-'.$thread->id.'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /**
     * An attached email (.eml) as a page: headers, attachments, the body;
     * ?part=n gives its n-th attachment.
     */
    public function email(Request $request, $id)
    {
        $attachment = Attachment::findOrFail($id);
        $thread = $attachment->thread;
        if (!$thread || !$thread->conversation || !auth()->user()->can('view', $thread->conversation)) {
            abort(403);
        }
        if (!self::isEmail($attachment)) {
            abort(404);
        }
        $message = \App\Incoming\Parser::parse((string) $attachment->getFileContents());
        $parts = array_values($message->attachments());

        if ($request->has('part')) {
            $part = $parts[(int) $request->part] ?? null;
            if (!$part) {
                abort(404);
            }

            return response($part->getContent(), 200, [
                'Content-Type'        => $part->getMimeType() ?: 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="'.addcslashes($part->getName(), '"\\').'"',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        // Images the body refers to (cid:) are shown in it; the rest listed.
        $body = $message->htmlBody();
        $listed = [];
        foreach ($parts as $i => $part) {
            $cid = trim((string) $part->id, '<>');
            if ($body !== '' && $cid !== '' && str_contains($body, 'cid:'.$cid) && str_starts_with((string) $part->getMimeType(), 'image/')) {
                $body = str_replace('cid:'.$cid, 'data:'.$part->getMimeType().';base64,'.base64_encode((string) $part->getContent()), $body);
            } else {
                $listed[$i] = $part;
            }
        }
        $body = $body !== '' ? \Helper::stripDangerousTags($body) : nl2br(e((string) $message->textBody()));

        return response()->view('conversations/attachment_email', [
            'attachment' => $attachment,
            'message'    => $message,
            'parts'      => $listed,
            'body'       => $body,
        ])->header('Content-Security-Policy', "default-src 'none'; img-src data:; style-src 'unsafe-inline'");
    }

    /**
     * Words that, in a reply without attachments, make Tallport ask before
     * sending (Settings » General).
     */
    const REMINDER_OPTION = 'attachment_reminder_phrases';

    const REMINDER_DEFAULT = "attachment\nattached\nattaching";

    public static function reminderPhrases()
    {
        $text = \Option::get(self::REMINDER_OPTION, self::REMINDER_DEFAULT);

        return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', strip_tags((string) $text))), 'strlen'));
    }

    public static function isEmail(Attachment $attachment)
    {
        return strtolower((string) $attachment->mime_type) == 'message/rfc822'
            || strtolower(pathinfo((string) $attachment->file_name, PATHINFO_EXTENSION)) == 'eml';
    }
}
