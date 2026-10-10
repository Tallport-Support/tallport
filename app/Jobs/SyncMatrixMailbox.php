<?php

namespace App\Jobs;

use App\Matrix\MatrixMailbox;
use App\Matrix\Syncer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SyncMatrixMailbox implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $identity_id;
    public $timeout = 300;
    public $tries = 1;
    public $uniqueFor = 360;

    public function __construct($identity_id)
    {
        $this->identity_id = $identity_id;
        $this->onConnection(\Helper::queueConnection('emails'));
        $this->onQueue('emails');
    }

    public function uniqueId(): string
    {
        return (string) $this->identity_id;
    }

    public function handle()
    {
        $identity = MatrixMailbox::find($this->identity_id);
        if (!$identity) {
            return;
        }
        try {
            (new Syncer())->run($identity);
        } catch (\Throwable $e) {
            \App\Misc\ChatLog::failure('matrix', $identity->mailbox_id, 'connection', $e);
            $identity->error = $e instanceof \App\Matrix\MatrixException ? $e->getMessage() : 'Matrix sync could not complete.';
            if ($e->getCode() === 401) {
                $identity->status = 'login';
            }
            $identity->save();
        }
    }
}
