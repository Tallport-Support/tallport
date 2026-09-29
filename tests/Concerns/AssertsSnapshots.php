<?php

namespace Tests\Concerns;

/**
 * Compares data against a JSON snapshot in tests/Snapshots.
 *
 * A missing snapshot is written and the test fails, so new snapshots get
 * reviewed and committed. After an intended change, rewrite snapshots with
 * UPDATE_SNAPSHOTS=1 ./test.sh and review the diff before committing.
 */
trait AssertsSnapshots
{
    protected function assertMatchesSnapshot(string $name, $data): void
    {
        $file = __DIR__.'/../Snapshots/'.$name.'.json';
        $actual = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

        if (!file_exists($file) || getenv('UPDATE_SNAPSHOTS')) {
            $existed = file_exists($file);
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0755, true);
            }
            file_put_contents($file, $actual);
            if (!$existed) {
                $this->fail("Snapshot tests/Snapshots/{$name}.json did not exist and was created: review and commit it.");
            }
        }

        $this->assertSame(
            file_get_contents($file),
            $actual,
            "Snapshot {$name} changed. If the change is intended, run UPDATE_SNAPSHOTS=1 ./test.sh and commit the new snapshot."
        );
    }
}
