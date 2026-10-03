<?php

namespace App\Jobs;

use App\Workflow;
use App\Workflows\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Run an automatic workflow on the existing conversations that meet its
 * conditions ("Apply to previous conversations").
 */
class ApplyWorkflow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $workflow_id;

    public $tries = 1;

    public $timeout = 3600;

    public function __construct($workflow_id)
    {
        $this->workflow_id = $workflow_id;
    }

    public function handle()
    {
        $workflow = Workflow::find($this->workflow_id);
        if ($workflow && $workflow->active && $workflow->isAutomatic() && $workflow->apply_to_prev) {
            Runner::processWorkflow($workflow);
        }
    }
}
