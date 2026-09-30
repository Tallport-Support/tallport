<?php

/**
 * Parts of the application that no test exercises, on purpose.
 * Format: 'item as printed by tests/inventory.php' => 'reason'.
 */

$network = 'needs freescout.net (license server or module downloads)';
$modules_on_disk = 'builds, installs or migrates modules in Modules/ and public/modules, which tests must not touch';
$translations = 'translation manager: writes resources/lang files, or sends translations to freescout.net';
$oauth = 'OAuth flow with Microsoft/Google servers';

return [
    // Not actions: license statuses in a switch inside ModulesController::ajax().
    'ajax ModulesController@ajax disabled'      => 'not an action (license status case)',
    'ajax ModulesController@ajax expired'       => 'not an action (license status case)',
    'ajax ModulesController@ajax inactive'      => 'not an action (license status case)',
    'ajax ModulesController@ajax site_inactive' => 'not an action (license status case)',

    'ajax ModulesController@ajax update'     => $network,
    'ajax ModulesController@ajax update_all' => $network,

    'command freescout:module-build'   => $modules_on_disk,
    'command freescout:module-install' => $modules_on_disk,
    'command freescout:module-laroute' => $modules_on_disk,
    'command module:migrate'           => $modules_on_disk,
    'command freescout:clean-tmp'      => 'cleans the real system temp dir; its logic (CleanTmp::cleanDirectory) is tested on a scratch dir',

    'job App\Jobs\RestartQueueWorker' => 'calls exit() to stop the queue worker',

    'route GET mailbox/oauth'                                     => $oauth,
    'route GET mailbox/oauth/{id}/{in_out}/{provider}'            => $oauth,
    'route GET mailbox/oauth-disconnect/{id}/{in_out}/{provider}' => $oauth,

    'route POST translations/add/{groupKey}'                       => $translations,
    'route POST translations/delete/{groupKey}/{translationKey}'   => $translations,
    'route POST translations/download'                             => $translations,
    'route POST translations/edit/{groupKey}'                      => $translations,
    'route POST translations/find'                                 => $translations,
    'route POST translations/groups/add'                           => $translations,
    'route POST translations/import'                               => $translations,
    'route POST translations/locales/add'                          => $translations,
    'route POST translations/locales/remove'                       => $translations,
    'route POST translations/publish/{groupKey}'                   => $translations,
    'route POST translations/removeUnpublished'                    => $translations,
    'route POST translations/send'                                 => $translations,

    // Route::redirect('/home') registers every verb; GET is tested.
    'route POST home'    => 'redirect route registered for every verb; GET is tested',
    'route PUT home'     => 'redirect route registered for every verb; GET is tested',
    'route PATCH home'   => 'redirect route registered for every verb; GET is tested',
    'route DELETE home'  => 'redirect route registered for every verb; GET is tested',
    'route OPTIONS home' => 'redirect route registered for every verb; GET is tested',
];
