<?php

namespace App\Http\Controllers;

use App\Mailbox;

class MatrixController extends Controller
{
    public function mailboxSettings($id)
    {
        $mailbox = Mailbox::findOrFail($id);
        $this->authorize('update', $mailbox);

        return view('matrix.mailbox_settings', ['mailbox' => $mailbox]);
    }
}
