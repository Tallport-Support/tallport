<?php

namespace App\Http\Controllers;

use App\Inbox\Sync;
use Illuminate\Http\Request;

/**
 * The browser's local copy of the inbox (App\Inbox\Sync, public/js/inbox.js).
 */
class InboxController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * ?page=N[&folder=ID]: a page of the first sync (the folder in view first); ?cursor=N: what
     * changed since.
     */
    public function sync(Request $request)
    {
        $sync = new Sync($request->user());
        $data = $request->has('cursor') ? $sync->since($request->cursor) : $sync->full(max(0, (int) $request->page), (int) $request->folder);

        return response()->json($data)->header('Cache-Control', 'no-store');
    }

    /**
     * A conversation fetched ahead (public/js/conversations.js): its pane, toolbar and customer
     * as they'd show, rendered without what opening it does (read, viewing, AI requests:
     * Sync::prefetching()).
     */
    public function pane(Request $request, $id)
    {
        $conversation = \App\Conversation::find($id);
        if (!$conversation || $conversation->state == \App\Conversation::STATE_DRAFT || !$request->user()->can('view', $conversation)) {
            abort(404);
        }
        $request->attributes->set('inbox_prefetch', true);
        $folder = $conversation->folder;

        return response()->json([
            'id'      => $conversation->id,
            'version' => Sync::paneVersions([$conversation])[$conversation->id],
            'parts'   => [
                '.conv-pane'            => \Livewire\Livewire::mount('conversation-pane', ['conversation' => $conversation, 'folder' => $folder]),
                '#conv-toolbar'         => \Livewire\Livewire::mount('conversation-toolbar', ['conversation' => $conversation]),
                '#conv-layout-customer' => \Livewire\Livewire::mount('conversation-inspector', ['conversation' => $conversation, 'folder' => $folder]),
            ],
        ])->header('Cache-Control', 'no-store');
    }
}
