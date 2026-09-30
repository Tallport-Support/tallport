<?php
/**
 * Inventory check: everything the application exposes (routes, ajax
 * actions, artisan commands, queued jobs) must be exercised by at least one
 * test, or be listed in tests/inventory-exclusions.php with a reason.
 *
 *   php tests/inventory.php <exercised log>
 *
 * ./test.sh runs it after a full test run; the log is written by
 * Tests\Support\ExercisedRecorder.
 */

$exercised_log = $argv[1] ?? '';
if (!$exercised_log || !file_exists($exercised_log)) {
    fwrite(STDERR, "Usage: php tests/inventory.php <exercised log from a full test run>\n");
    exit(2);
}

$snapshots = __DIR__.'/Snapshots';
$inventory = [];

foreach (json_decode(file_get_contents($snapshots.'/routes.json'), true) as $route) {
    foreach ($route['methods'] as $method) {
        $inventory[] = 'route '.$method.' '.$route['uri'];
    }
}
foreach (json_decode(file_get_contents($snapshots.'/ajax_actions.json'), true) as $endpoint => $actions) {
    foreach ($actions as $action) {
        $inventory[] = 'ajax '.$endpoint.' '.$action;
    }
}
foreach (array_keys(json_decode(file_get_contents($snapshots.'/console_commands.json'), true)) as $command) {
    $inventory[] = 'command '.$command;
}
foreach (glob(__DIR__.'/../app/Jobs/*.php') as $file) {
    $inventory[] = 'job App\\Jobs\\'.basename($file, '.php');
}
$inventory = array_unique($inventory);
sort($inventory);

$exercised = array_unique(array_filter(array_map('trim', file($exercised_log))));
$exclusions = require __DIR__.'/inventory-exclusions.php';

$missing = array_values(array_diff($inventory, $exercised, array_keys($exclusions)));
$stale = array_values(array_intersect(array_keys($exclusions), $exercised));
$unknown = array_values(array_diff(array_keys($exclusions), $inventory));

$covered = count(array_intersect($inventory, $exercised));
printf(
    "Inventory: %d items, %d exercised by tests, %d excluded, %d missing.\n",
    count($inventory), $covered, count(array_intersect($inventory, array_keys($exclusions))), count($missing)
);

foreach ($stale as $item) {
    echo "  Now exercised, remove from tests/inventory-exclusions.php: $item\n";
}
foreach ($unknown as $item) {
    echo "  Excluded but no longer in the application, remove from tests/inventory-exclusions.php: $item\n";
}
if ($missing) {
    echo "Not exercised by any test (write a test, or exclude with a reason in tests/inventory-exclusions.php):\n";
    foreach ($missing as $item) {
        echo "  $item\n";
    }
    exit(1);
}
