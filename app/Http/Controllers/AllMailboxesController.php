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

    /**
     * A folder of All Mailboxes, and a conversation beside its list (App\Misc\Addresses):
     * /mailbox/all/unassigned[/1042[/58231]]. Older addresses (folder IDs) work too; the page then shows its own (data-page-url).
     */
    public function view(Request $request, $folder_id = null, $number = null, $thread_id = null)
    {
        $user = auth()->user();
        if (!AllMailboxes::isAvailable($user)) {
            return redirect()->route('dashboard', ['dashboard' => 1]);
        }

        $folders = AllMailboxes::folders($user);
        $folder = \App\Misc\Addresses::findFolder(null, $folder_id ?: \App\Misc\Addresses::FOLDER_WORDS[Folder::TYPE_UNASSIGNED], $user);
        if (!$folder) {
            abort(404);
        }
        if ($number) {
            $conversation = \App\Misc\Addresses::findConversation(null, $number);
            if (!$conversation || !$user->can('viewCached', $conversation)) {
                abort(404);
            }
            \App\Misc\Sidebar::select($folder);
            $request->attributes->set('address_folder', $folder);
            $request->attributes->set('address_thread', $thread_id ? (int) $thread_id : null);

            return app(ConversationsController::class)->view($request, $conversation->id);
        }
        \App\Misc\Sidebar::select($folder);

        $query = AllMailboxes::query($folder, $user);
        $conversations = $folder->queryAddOrderBy($query)->paginate(Conversation::DEFAULT_LIST_SIZE, ['*'], 'page', $request->get('page'));
        if ($open = ConversationsController::openFolder($request, $folder, $query, $conversations)) {
            return $open;
        }

        return view('mailboxes/view', [
            'mailbox'       => AllMailboxes::mailbox(),
            'folders'       => $folders,
            'folder'        => $folder,
            'conversations' => $conversations,
            'params'        => ['show_mailbox' => true],
            'sidebar_counts' => \App\Misc\Sidebar::unreadCounts($user),
        ]);
    }
}
