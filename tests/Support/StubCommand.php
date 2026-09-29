<?php

namespace Tests\Support;

use Illuminate\Console\Command;

/**
 * Stands in for an artisan command that must not really run in tests,
 * recording that it was called.
 */
class StubCommand extends Command
{
    /**
     * Calls made in the current test: [['name' => ..., 'arguments' => [...]], ...].
     */
    public static $calls = [];

    public function __construct($name)
    {
        $this->name = $name;

        parent::__construct();

        // Accept whatever arguments and options the application passes.
        $this->ignoreValidationErrors();
    }

    public function handle()
    {
        static::$calls[] = [
            'name'      => $this->getName(),
            'arguments' => (string)$this->input,
        ];
    }
}
