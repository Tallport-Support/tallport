<?php

namespace App\Http\Controllers;

use App\Misc\ChatLog;
use Illuminate\Http\Request;

class ChannelLogsController extends Controller
{
    public function telegram(Request $request)
    {
        return $this->show($request, 'telegram');
    }

    public function nostr(Request $request)
    {
        return $this->show($request, 'nostr');
    }

    public function matrix(Request $request)
    {
        return $this->show($request, 'matrix');
    }

    private function show(Request $request, $channel)
    {
        return view('secure/channel_log', ChatLog::page($channel, (string) $request->input('outcome'), (int) $request->input('mailbox_id')));
    }
}
