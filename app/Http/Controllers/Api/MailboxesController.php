<?php

namespace App\Http\Controllers\Api;

use App\Api\Format;
use App\Folder;
use App\Mailbox;
use App\User;
use Illuminate\Http\Request;

class MailboxesController extends ApiController
{
    /**
     * GET /api/mailboxes?userId=: mailboxes (of a user).
     */
    public function index(Request $request)
    {
        $query = Mailbox::orderBy('id');
        if (!$this->access()->isGlobal()) {
            $query->whereIn('id', $this->access()->mailboxIds());
        }
        if ($user_id = $this->param($request, 'userId')) {
            $user = User::find($user_id);
            if (!$user) {
                return $this->error('User not found', 'userId');
            }
            $query->whereIn('id', $user->mailboxesIdsCanView());
        }
        $query = \Eventy::filter('api.mailboxes.query', $query, $request);

        return $this->paginated($request, $query, 'mailboxes', function ($mailbox) {
            return Format::mailbox($mailbox);
        });
    }

    /**
     * GET /api/mailboxes/{id}/folders?userId=&folderId=
     */
    public function folders(Request $request, $id)
    {
        $mailbox = \Eventy::filter('api.mailbox.find', Mailbox::find($id), $request);
        if (!$mailbox) {
            return $this->notFound();
        }
        if (!$this->access()->canMailbox($mailbox->id)) {
            return $this->forbiddenMailbox();
        }
        $query = Folder::where('mailbox_id', $mailbox->id)->orderBy('type');
        if ($user_id = $this->param($request, 'userId')) {
            $query->where(function ($query) use ($user_id) {
                $query->whereNull('user_id')->orWhere('user_id', $user_id);
            });
        }
        if ($folder_id = $this->param($request, 'folderId')) {
            $query->where('id', $folder_id);
        }

        return $this->paginated($request, $query, 'folders', function ($folder) {
            return Format::folder($folder);
        });
    }
}
