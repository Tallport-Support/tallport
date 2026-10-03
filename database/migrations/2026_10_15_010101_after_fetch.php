<?php

use Illuminate\Database\Migrations\Migration;

/**
 * What fetching does with emails on the mail server (App\Incoming\AfterFetch)
 * is built in: a mailbox's setting kept by a module (meta "imapmove") becomes
 * Tallport's, and the module is switched off.
 */
class AfterFetch extends Migration
{
    public function up()
    {
        foreach (\App\Mailbox::all() as $mailbox) {
            self::importSettings($mailbox);
        }

        try {
            if (\App\Module::isActive('imapmove')) {
                \App\Module::setActive('imapmove', false);
            }
        } catch (\Throwable $e) {
            // The modules aren't loaded (fresh install).
        }
    }

    public function down()
    {
    }

    public static function importSettings(\App\Mailbox $mailbox)
    {
        $old = (array) ($mailbox->getMeta('imapmove') ?? []);
        if (!$old || $mailbox->getMeta(\App\Incoming\AfterFetch::META) !== null) {
            return;
        }
        $actions = [2 => \App\Incoming\AfterFetch::REMOVE, 3 => \App\Incoming\AfterFetch::MOVE];
        \App\Incoming\AfterFetch::save($mailbox, $actions[(int) ($old['action'] ?? 0)] ?? \App\Incoming\AfterFetch::LEAVE, $old['folder'] ?? '');
        $mailbox->save();
    }
}
