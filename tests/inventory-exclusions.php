<?php

/**
 * Parts of the application that no test exercises, on purpose.
 * Format: 'item as printed by tests/inventory.php' => 'reason'.
 */

$network = 'downloads modules from their own addresses';
$modules_on_disk = 'builds, installs or migrates modules in Modules/ and public/modules, which tests must not touch';
$oauth = 'OAuth flow with Microsoft/Google servers';
$livewire = "Livewire's own route (its prefix comes from the app key); not used by a Tallport page yet";

return [
    'ajax ModulesController@ajax update'     => $network,
    'ajax ModulesController@ajax update_all' => $network,

    'command tallport:module-build'   => $modules_on_disk,
    'command tallport:module-install' => $modules_on_disk,
    'command tallport:module-laroute' => $modules_on_disk,
    'command module:migrate'           => $modules_on_disk,
    'command tallport:clean-tmp'      => 'cleans the real system temp dir; its logic (CleanTmp::cleanDirectory) is tested on a scratch dir',

    'job App\Jobs\RestartQueueWorker' => 'calls exit() to stop the queue worker',

    'route GET livewire-0607dabf/css/{component}.css' => $livewire,
    'route GET livewire-0607dabf/css/{component}.global.css' => $livewire,
    'route GET livewire-0607dabf/js/{component}.js' => $livewire,
    'route GET livewire-0607dabf/livewire.csp.min.js.map' => $livewire,
    'route GET livewire-0607dabf/livewire.min.js' => $livewire,
    'route GET livewire-0607dabf/livewire.min.js.map' => $livewire,
    'route GET livewire-0607dabf/preview-file/{filename}' => $livewire,
    'route POST livewire-0607dabf/upload-file' => $livewire,

    'route GET mailbox/oauth'                                     => $oauth,
    'route GET mailbox/oauth/{id}/{in_out}/{provider}'            => $oauth,
    'route GET mailbox/oauth-disconnect/{id}/{in_out}/{provider}' => $oauth,


    // Route::redirect('/home') registers every verb; GET is tested.
    'route POST home'    => 'redirect route registered for every verb; GET is tested',
    'route PUT home'     => 'redirect route registered for every verb; GET is tested',
    'route PATCH home'   => 'redirect route registered for every verb; GET is tested',
    'route DELETE home'  => 'redirect route registered for every verb; GET is tested',
    'route OPTIONS home' => 'redirect route registered for every verb; GET is tested',
];
