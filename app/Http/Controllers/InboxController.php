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
}
