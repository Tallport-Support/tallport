<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
| Every time you change routes, run the following command to make them available in JS:
|     php artisan tallport:build
*/

// Login routes are below, at the configurable login path.
Auth::routes(['login' => false, 'register' => false]);

// Logging in (Laravel Fortify, App\Providers\FortifyServiceProvider): a
// password, then a two-factor code if the user has it on; or a passkey.
Route::group(['middleware' => 'guest'], function () {
	Route::get(config('app.login_path'), [\Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::class, 'create'])->name('login');
	Route::post(config('app.login_path'), [\Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::class, 'store'])->name('login.store');
	Route::get('/two-factor-challenge', [\Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController::class, 'create'])->name('two-factor.login');
	Route::post('/two-factor-challenge', [\Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController::class, 'store'])->middleware('throttle:two-factor')->name('two-factor.login.store');
	Route::get('/passkeys/login/options', [\Laravel\Passkeys\Http\Controllers\PasskeyLoginController::class, 'index'])->middleware('throttle:passkeys')->name('passkey.login-options');
	Route::post('/passkeys/login', [\Laravel\Passkeys\Http\Controllers\PasskeyLoginController::class, 'store'])->middleware('throttle:passkeys')->name('passkey.login');
});
Route::group(['middleware' => 'auth'], function () {
	Route::group(['middleware' => 'password.confirm'], function () {
		Route::post('/user/two-factor-authentication', [\Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController::class, 'store'])->name('two-factor.enable');
		Route::post('/user/confirmed-two-factor-authentication', [\Laravel\Fortify\Http\Controllers\ConfirmedTwoFactorAuthenticationController::class, 'store'])->name('two-factor.confirm');
		Route::delete('/user/two-factor-authentication', [\Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController::class, 'destroy'])->name('two-factor.disable');
		Route::post('/user/two-factor-recovery-codes', [\Laravel\Fortify\Http\Controllers\RecoveryCodeController::class, 'store'])->name('two-factor.recovery-codes.store');
		Route::get('/user/passkeys/options', [\Laravel\Passkeys\Http\Controllers\PasskeyRegistrationController::class, 'index'])->middleware('throttle:passkeys')->name('passkey.registration-options');
		Route::post('/user/passkeys', [\Laravel\Passkeys\Http\Controllers\PasskeyRegistrationController::class, 'store'])->middleware('throttle:passkeys')->name('passkey.store');
		Route::delete('/user/passkeys/{passkey}', [\Laravel\Passkeys\Http\Controllers\PasskeyRegistrationController::class, 'destroy'])->name('passkey.destroy');
	});
});

// Authentication redirects to /home
// if APP_DASHBOARD_PATH is empty APP_URL will be used
if (config('app.dashboard_path')) {
	Route::redirect('/home', config('app.url').'/'.config('app.dashboard_path'), 302);
} else {
	Route::redirect('/home', config('app.url'), 302);
}

// Open routes
Route::get('/user-setup/{hash}/{invite_sent_at}', 'OpenController@userSetup')->name('user_setup');
Route::post('/user-setup/{hash}/{invite_sent_at}', 'OpenController@userSetupSave');

// General routes for logged in users
if (config('app.dashboard_path')) {
	Route::get('/', config('app.home_controller'));
}
Route::get('/'.config('app.dashboard_path'), 'SecureController@dashboard')->name('dashboard');
Route::get('/app-logs/app', ['uses' => 'AppLogsController@index', 'middleware' => ['auth', 'roles', 'can:operate'], 'roles' => ['admin']])->name('logs.app');
Route::get('/app-logs/ai', ['uses' => 'AiLogController@index', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('logs.ai');
Route::get('/app-logs/out_telegram', ['uses' => 'ChannelLogsController@telegram', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('logs.telegram');
Route::get('/app-logs/out_nostr', ['uses' => 'ChannelLogsController@nostr', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('logs.nostr');
Route::get('/app-logs/out_matrix', ['uses' => 'ChannelLogsController@matrix', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('logs.matrix');
Route::get('/app-logs/{name?}', ['uses' => 'SecureController@logs', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('logs');
Route::post('/app-logs/{name?}', ['uses' => 'SecureController@logsSubmit', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('logs.action');

// Settings
Route::get('/app-settings/api/webhook/{id}', ['uses' => 'ApiSettingsController@webhook', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->where('id', 'new|[0-9]+')->name('settings.api.webhook');
Route::post('/app-settings/api/action', ['uses' => 'ApiSettingsController@action', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('settings.api.action');
Route::post('/app-settings/ajax', ['uses' => 'SettingsController@ajax', 'middleware' => ['auth', 'roles'], 'roles' => ['admin'], 'laroute' => true])->name('settings.ajax');
Route::get('/app-settings/{section?}', ['uses' => 'SettingsController@view', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('settings');
Route::post('/app-settings/{section?}', ['uses' => 'SettingsController@save', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('settings.save');

// Nostr
Route::get('/customers/{id}/nostr', ['uses' => 'NostrController@customerKeys', 'middleware' => ['auth']])->name('customers.nostr');
Route::post('/customers/{id}/nostr', ['uses' => 'NostrController@customerKeysSave', 'middleware' => ['auth']])->name('customers.nostr.save');

// AI Assistant
Route::post('/ai-assistant/customer-context/test', ['uses' => 'AiDraftsController@testCustomerContext', 'middleware' => ['auth', 'roles'], 'roles' => ['admin'], 'laroute' => true])->name('ai.customer_context.test');
// AI Assistant reply drafts
Route::post('/ai-assistant/conversations/{id}/draft-reply', 'AiDraftsController@store')->middleware(['auth', 'throttle:30,1'])->name('ai.drafts.store');

// Users
Route::get('/users', ['uses' => 'UsersController@users', 'laroute' => true])->name('users');
Route::get('/users/wizard', 'UsersController@create')->name('users.create');
Route::post('/users/wizard', 'UsersController@createSave');
Route::get('/users/profile/{id}', 'UsersController@profile')->name('users.profile');
Route::get('/users/permissions/{id}', 'UsersController@permissions')->name('users.permissions');
Route::get('/users/notifications/{id}', 'UsersController@notifications')->name('users.notifications');
Route::post('/users/notifications/{id}', 'UsersController@notificationsSave')->name('users.notifications.save');
Route::get('/users/api-keys/{id}', 'ApiKeysController@index')->middleware('password.confirm')->name('users.api_keys');
Route::post('/users/api-keys/{id}', 'ApiKeysController@action')->middleware('password.confirm')->name('users.api_keys.action');
Route::get('/users/security/{id}', 'UserSecurityController@show')->middleware('password.confirm')->name('users.security');
Route::post('/users/security/{id}/reset', 'UserSecurityController@reset')->middleware('password.confirm')->name('users.security.reset');
Route::post('/users/security/{id}/forget-devices', 'UserSecurityController@forgetDevices')->name('users.security.forget_devices');
Route::get('/users/preferences/{id}', 'UsersController@preferences')->name('users.preferences');
Route::post('/users/preferences/{id}', 'UsersController@preferencesSave')->name('users.preferences.save');
Route::get('/users/password/{id}', 'UsersController@password')->name('users.password');
Route::post('/users/password/{id}', 'UsersController@passwordSave')->name('users.password.save');
Route::post('/users/ajax', ['uses' => 'UsersController@ajax', 'laroute' => true])->name('users.ajax');
// One's own settings, the same for everyone: no user id, so they always mean "me" (the pages
// above with one's own id lead here; Users › a person is managing them).
Route::get('/profile', 'UsersController@ownProfile')->name('own.profile');
Route::get('/profile/password', 'UsersController@ownPassword')->name('own.password');
Route::get('/security', 'UserSecurityController@own')->middleware('password.confirm')->name('own.security');
Route::get('/preferences', 'UsersController@ownPreferences')->name('own.preferences');
Route::get('/notifications', 'UsersController@ownNotifications')->name('own.notifications');
Route::get('/api-keys', 'ApiKeysController@own')->middleware('password.confirm')->name('own.api_keys');

// Conversations
// The browser's local copy of the inbox (App\Inbox\Sync).
Route::get('/inbox/sync', 'InboxController@sync')->name('inbox.sync');
Route::get('/inbox/pane/{id}', 'InboxController@pane')->name('inbox.pane');
Route::get('/conversation/{id}/{thread_id?}', ['uses' => 'ConversationsController@view', 'laroute' => true])->where(['id' => '\d+', 'thread_id' => '\d+'])->name('conversations.view');
Route::post('/conversation/ajax', ['uses' => 'ConversationsController@ajax', 'laroute' => true])->name('conversations.ajax');
Route::post('/attachments/{id}/delete', ['uses' => 'AttachmentsController@delete', 'laroute' => true])->name('attachments.delete');
Route::get('/attachments/{id}/email', 'AttachmentsController@email')->name('attachments.email');
Route::get('/thread/{thread_id}/attachments', 'AttachmentsController@download')->name('attachments.download_all');
Route::post('/conversation/external-images', ['uses' => 'ExternalImagesController@ajax', 'laroute' => true])->name('conversations.external_images');
Route::post('/conversation/upload', ['uses' => 'ConversationsController@upload', 'laroute' => true])->middleware('throttle:100,1')->name('conversations.upload');
Route::get('/mailbox/{mailbox_id}/new-ticket', 'ConversationsController@create')->name('conversations.create');
Route::post('/mailbox/{mailbox_id}/clone-ticket/{from_thread_id}', 'ConversationsController@cloneConversation')->name('conversations.clone_conversation.submit');
//Route::get('/conversation/draft/{id}', 'ConversationsController@draft')->name('conversations.draft');
Route::get('/conversation/ajax-html/{action}', ['uses' => 'ConversationsController@ajaxHtml', 'laroute' => true])->name('conversations.ajax_html');
// No extension in the address: web servers may serve those as files (the name comes with the download).
Route::get('/thread/{thread_id}/original', 'ConversationsController@originalEml')->name('threads.original_eml');
Route::get('/search', 'ConversationsController@search')->name('conversations.search');
Route::post('/conversation/undo-reply/{thread_id}', 'ConversationsController@undoReply')->name('conversations.undo.submit');

// Mailboxes
Route::get('/mailboxes', ['uses' => 'MailboxesController@mailboxes', 'laroute' => true])->name('mailboxes');
Route::get('/mailbox/new', 'MailboxesController@create')->name('mailboxes.create');
Route::post('/mailbox/new', 'MailboxesController@createSave');
// A mailbox's settings, in the mailbox: by its short name or its ID (MailboxAddress). /settings is
// where they start: General, or in a narrow window the list of pages (MailboxesController::update()).
Route::group(['middleware' => 'mailbox.address', 'where' => ['id' => '[a-z0-9-]+', 'mailbox_id' => '[a-z0-9-]+']], function () {
    Route::get('/mailbox/{id}/settings', 'MailboxesController@update')->name('mailboxes.update');
    Route::get('/mailbox/{id}/settings/general', 'MailboxesController@update')->name('mailboxes.general');
    Route::post('/mailbox/{id}/settings/general', 'MailboxesController@updateSave')->name('mailboxes.update.save');
    Route::get('/mailbox/{id}/settings/connection', 'MailboxesController@connectionOutgoing')->name('mailboxes.connection');
    Route::post('/mailbox/{id}/settings/connection', 'MailboxesController@connectionOutgoingSave')->name('mailboxes.connection.save');
    Route::get('/mailbox/{id}/settings/connection/incoming', 'MailboxesController@connectionIncoming')->name('mailboxes.connection.incoming');
    Route::post('/mailbox/{id}/settings/connection/incoming', 'MailboxesController@connectionIncomingSave')->name('mailboxes.connection.incoming.save');
    Route::get('/mailbox/{id}/settings/permissions', 'MailboxesController@permissions')->name('mailboxes.permissions');
    Route::post('/mailbox/{id}/settings/permissions', 'MailboxesController@permissionsSave')->name('mailboxes.permissions.save');
    Route::get('/mailbox/{id}/settings/auto-reply', 'MailboxesController@autoReply')->name('mailboxes.auto_reply');
    Route::post('/mailbox/{id}/settings/auto-reply', 'MailboxesController@autoReplySave')->name('mailboxes.auto_reply.save');
    Route::get('/mailbox/{id}/settings/website-chat', ['uses' => 'WebChatSettingsController@settings', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('mailboxes.web_chat');
    Route::post('/mailbox/{id}/settings/website-chat', ['uses' => 'WebChatSettingsController@settingsSave', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('mailboxes.web_chat.save');
    Route::get('/mailbox/{id}/settings/telegram', ['uses' => 'TelegramController@settings', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('mailboxes.telegram');
    Route::post('/mailbox/{id}/settings/telegram', ['uses' => 'TelegramController@settingsSave', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('mailboxes.telegram.save');
    Route::get('/mailbox/{id}/settings/matrix', ['uses' => 'MatrixController@mailboxSettings', 'middleware' => ['auth']])->name('mailboxes.matrix');
    Route::get('/mailbox/{id}/settings/nostr', ['uses' => 'NostrController@mailboxSettings', 'middleware' => ['auth']])->name('mailboxes.nostr');
    Route::post('/mailbox/{id}/settings/nostr', ['uses' => 'NostrController@mailboxSettingsSave', 'middleware' => ['auth']])->name('mailboxes.nostr.save');
    Route::get('/mailbox/{id}/settings/ai', 'MailboxesController@ai')->name('mailboxes.ai');
    Route::post('/mailbox/{id}/settings/ai', 'MailboxesController@aiSave')->name('mailboxes.ai.save');
    Route::get('/mailbox/{id}/settings/documentation', ['uses' => 'AiDocumentsController@index', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('mailboxes.documentation');
    Route::post('/mailbox/{id}/settings/documentation', ['uses' => 'AiDocumentsController@action', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('mailboxes.documentation.action');
    Route::get('/mailbox/{mailbox_id}/settings/workflows', 'WorkflowsController@index')->name('mailboxes.workflows');
    Route::get('/mailbox/{mailbox_id}/settings/workflows/new', 'WorkflowsController@edit')->name('mailboxes.workflows.create');
    Route::post('/mailbox/{mailbox_id}/settings/workflows/save', 'WorkflowsController@save')->name('mailboxes.workflows.save');
    Route::get('/mailbox/{mailbox_id}/settings/workflows/{id}', 'WorkflowsController@edit')->where('id', '\d+')->name('mailboxes.workflows.update');
    Route::get('/mailbox/{id}/settings/saved-replies', 'SavedRepliesController@index')->name('mailboxes.saved_replies');
    Route::get('/mailbox/{id}/settings/saved-replies/new', 'SavedRepliesController@edit')->name('mailboxes.saved_replies.create');
    Route::post('/mailbox/{id}/settings/saved-replies/save', 'SavedRepliesController@save')->name('mailboxes.saved_replies.save');
    Route::get('/mailbox/{id}/settings/saved-replies/{saved_reply_id}', 'SavedRepliesController@edit')->where('saved_reply_id', '\d+')->name('mailboxes.saved_replies.edit');
    Route::post('/mailbox/{id}/settings/saved-replies/{saved_reply_id}/delete', 'SavedRepliesController@delete')->where('saved_reply_id', '\d+')->name('mailboxes.saved_replies.delete');
});
// Their older addresses: to these.
foreach ([
    '/mailbox/settings/{id}'                       => 'mailboxes.update',
    '/mailbox/connection-settings/{id}/outgoing'   => 'mailboxes.connection',
    '/mailbox/connection-settings/{id}/incoming'   => 'mailboxes.connection.incoming',
    '/mailbox/permissions/{id}'                    => 'mailboxes.permissions',
    '/mailbox/settings/{id}/auto-reply'            => 'mailboxes.auto_reply',
    '/mailbox/settings/{id}/website-chat'          => 'mailboxes.web_chat',
    '/mailbox/{id}/telegram'                       => 'mailboxes.telegram',
    '/mailbox/settings/{id}/matrix'                => 'mailboxes.matrix',
    '/mailbox/settings/{id}/nostr'                 => 'mailboxes.nostr',
    '/mailbox/settings/{id}/ai'                    => 'mailboxes.ai',
    '/mailbox/settings/{id}/documentation'         => 'mailboxes.documentation',
    '/mailbox/workflows/{mailbox_id}'              => 'mailboxes.workflows',
    '/mailbox/workflows/{mailbox_id}/new'          => 'mailboxes.workflows.create',
    '/mailbox/workflows/{mailbox_id}/{id}'         => 'mailboxes.workflows.update',
    '/mailbox/saved-replies/{id}'                  => 'mailboxes.saved_replies',
    '/mailbox/saved-replies/{id}/new'              => 'mailboxes.saved_replies.create',
    '/mailbox/saved-replies/{id}/{saved_reply_id}/edit' => 'mailboxes.saved_replies.edit',
] as $moved_uri => $moved_to) {
    Route::get($moved_uri, 'MailboxesController@settingsMoved')->where(['id' => '\d+', 'mailbox_id' => '\d+', 'saved_reply_id' => '\d+'])->defaults('to', $moved_to);
}
// Knowledge base
Route::get('/kb', 'KnowledgeBaseController@index')->name('kb');
Route::get('/kb/new', 'KnowledgeBaseController@edit')->name('kb.create');
Route::get('/kb/{id}', 'KnowledgeBaseController@show')->name('kb.article');
Route::get('/kb/{id}/edit', 'KnowledgeBaseController@edit')->name('kb.edit');
Route::post('/kb/save', 'KnowledgeBaseController@save')->name('kb.save');
Route::post('/kb/{id}/delete', 'KnowledgeBaseController@delete')->name('kb.delete');
Route::post('/kb/ajax', ['uses' => 'KnowledgeBaseController@ajax', 'laroute' => true])->name('kb.ajax');

// Workflows of all mailboxes (a mailbox's: in its settings).
Route::get('/workflows', 'WorkflowsController@globalIndex')->name('workflows');
Route::get('/workflows/new', 'WorkflowsController@globalEdit')->name('workflows.create');
Route::get('/workflows/{id}', 'WorkflowsController@globalEdit')->name('workflows.update');
Route::post('/workflows/save', 'WorkflowsController@globalSave')->name('workflows.save');
Route::post('/workflows/ajax', ['uses' => 'WorkflowsController@ajax', 'laroute' => true])->name('workflows.ajax');

// Reports
Route::get('/reports/conversations', 'ReportsController@conversations')->name('reports.conversations');
Route::get('/reports/productivity', 'ReportsController@productivity')->name('reports.productivity');

// Folders, and conversations beside their lists (App\Misc\Addresses): /mailbox/support/drafts/1042/58231.
Route::get('/mailbox/all/{folder_id?}', 'AllMailboxesController@view')->where('folder_id', '-?\d+|[a-z]+')->name('mailboxes.all');
Route::get('/mailbox/all/{folder_id}/{number}/{thread_id?}', 'AllMailboxesController@view')->where(['folder_id' => '[a-z]+|-?\d+', 'number' => '\d+', 'thread_id' => '\d+'])->name('mailboxes.all.conversation');
Route::get('/chat', 'MailboxesController@teamChatOpen')->name('team_chat');
Route::get('/chat/{id}', 'MailboxesController@teamChat')->name('mailboxes.team_chat');
Route::post('/saved-replies/ajax', ['uses' => 'SavedRepliesController@ajax', 'laroute' => true])->name('saved_replies.ajax');
Route::post('/mailbox/ajax', ['uses' => 'MailboxesController@ajax', 'laroute' => true])->name('mailboxes.ajax');
Route::get('/mailbox/oauth/{id}/{in_out}/{provider}', ['uses' => 'MailboxesController@oauth'])->name('mailboxes.oauth');
Route::get('/mailbox/oauth', ['uses' => 'MailboxesController@oauth'])->name('mailboxes.oauth_callback');
Route::post('/mailbox/oauth-disconnect/{id}/{in_out}/{provider}', ['uses' => 'MailboxesController@oauthDisconnect'])->name('mailboxes.oauth_disconnect.submit');

// Customers
Route::get('/customers/{id}/edit', 'CustomersController@update')->name('customers.update');
Route::post('/customers/{id}/edit', 'CustomersController@updateSave');
Route::get('/customers/{id}/', 'CustomersController@conversations')->name('customers.conversations');
Route::get('/customers/ajax-search', ['uses' => 'CustomersController@ajaxSearch', 'laroute' => true])->name('customers.ajax_search');
Route::post('/customers/ajax', ['uses' => 'CustomersController@ajax', 'laroute' => true])->name('customers.ajax');
Route::get('/customers/{id}/merge', 'CustomersController@merge')->name('customers.merge');
Route::post('/customers/{id}/merge', 'CustomersController@mergeSave');

// Modules
// There is a /public/modules folder, so route must have a different name
// The operator's (HOSTING.md): the Gate "operate" lets every admin through when there's no operator.
Route::get('/modules/list', ['uses' => 'ModulesController@modules', 'middleware' => ['auth', 'roles', 'can:operate'], 'roles' => ['admin']])->name('modules');
Route::get('/modules/list/{alias}', ['uses' => 'ModulesController@modules', 'middleware' => ['auth', 'roles', 'can:operate'], 'roles' => ['admin']])->name('modules.module');
Route::post('/modules/ajax', ['uses' => 'ModulesController@ajax', 'laroute' => true, 'middleware' => ['auth', 'roles', 'can:operate'], 'roles' => ['admin']])->name('modules.ajax');

// System
Route::get('/system/status', ['uses' => 'SystemController@status', 'middleware' => ['auth', 'roles', 'can:operate'], 'roles' => ['admin']])->name('system');
Route::get('/system/tools', ['uses' => 'SystemController@tools', 'middleware' => ['auth', 'roles', 'can:operate'], 'roles' => ['admin']])->name('system.tools');
Route::post('/system/tools', ['uses' => 'SystemController@toolsExecute', 'middleware' => ['auth', 'roles', 'can:operate'], 'roles' => ['admin']])->name('system.tools.action');
Route::post('/system/ajax', ['uses' => 'SystemController@ajax', 'laroute' => true, 'middleware' => ['auth', 'roles', 'can:operate'], 'roles' => ['admin']])->name('system.ajax');
Route::post('/system/action', ['uses' => 'SystemController@action', 'middleware' => ['auth', 'roles', 'can:operate'], 'roles' => ['admin']])->name('system.action');
Route::get('/system/ajax-html/{action}/{param?}', ['uses' => 'SystemController@ajaxHtml', 'middleware' => ['auth', 'roles', 'can:operate'], 'roles' => ['admin']])->name('system.ajax_html');

// Uploads
Route::post('/uploads/upload', ['uses' => 'SecureController@upload', 'laroute' => true])->middleware('throttle:100,1')->name('uploads.upload');

// A mailbox's folders, and conversations beside their lists (App\Misc\Addresses), by its short
// name or (older addresses) its ID: last, so the app's other /mailbox/... addresses match first.
Route::get('/mailbox/{id}', 'MailboxesController@view')->where('id', '[a-z0-9-]+')->name('mailboxes.view');
Route::get('/mailbox/{id}/{folder_id}', 'MailboxesController@view')->where(['id' => '[a-z0-9-]+', 'folder_id' => '[a-z]+|-?\d+'])->name('mailboxes.view.folder');
Route::get('/mailbox/{id}/{folder_id}/{number}/{thread_id?}', 'MailboxesController@view')->where(['id' => '[a-z0-9-]+', 'folder_id' => '[a-z]+|-?\d+', 'number' => '\d+', 'thread_id' => '\d+'])->name('mailboxes.view.conversation');
