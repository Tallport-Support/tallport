<?php

namespace App\Http\Controllers;

use App\Conversation;
use App\Folder;
use App\Misc\AllMailboxes;
use Illuminate\Http\Request;

/**
 * All Mailboxes: a folder of every mailbox of the user, as one list.
 */
class AllMailboxesController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function view(Request $request, $folder_id = null)
    {
        $user = auth()->user();
        if (!AllMailboxes::isAvailable($user)) {
            return redirect()->route('dashboard', ['dashboard' => 1]);
        }

        $folders = AllMailboxes::folders($user);
        $folder = $folders->firstWhere('id', (int) ($folder_id ?: -Folder::TYPE_UNASSIGNED));
        if (!$folder) {
            abort(404);
        }

        $query = AllMailboxes::query($folder, $user);
        $conversations = $folder->queryAddOrderBy($query)->paginate(Conversation::DEFAULT_LIST_SIZE, ['*'], 'page', $request->get('page'));

        return view('mailboxes/view', [
            'mailbox'       => AllMailboxes::mailbox(),
            'folders'       => $folders,
            'folder'        => $folder,
            'conversations' => $conversations,
            'params'        => ['show_mailbox' => true],
        ]);
    }
}
