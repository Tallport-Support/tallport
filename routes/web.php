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
Auth::routes(['login' => false]);

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
	Route::get('/user/confirm-password', [\Laravel\Fortify\Http\Controllers\ConfirmablePasswordController::class, 'show'])->name('password.confirm');
	Route::post('/user/confirm-password', [\Laravel\Fortify\Http\Controllers\ConfirmablePasswordController::class, 'store'])->name('password.confirm.store');
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
Route::get('/app-logs/app', ['uses' => 'AppLogsController@index', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('logs.app');
Route::get('/app-logs/{name?}', ['uses' => 'SecureController@logs', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('logs');
Route::post('/app-logs/{name?}', ['uses' => 'SecureController@logsSubmit', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('logs.action');

// Settings
Route::post('/app-settings/api/action', ['uses' => 'ApiSettingsController@action', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('settings.api.action');
Route::post('/app-settings/ajax', ['uses' => 'SettingsController@ajax', 'middleware' => ['auth', 'roles'], 'roles' => ['admin'], 'laroute' => true])->name('settings.ajax');
Route::get('/app-settings/{section?}', ['uses' => 'SettingsController@view', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('settings');
Route::post('/app-settings/{section?}', ['uses' => 'SettingsController@save', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('settings.save');

// Telegram
Route::get('/mailbox/{id}/telegram', ['uses' => 'TelegramController@settings', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('mailboxes.telegram');
Route::post('/mailbox/{id}/telegram', ['uses' => 'TelegramController@settingsSave', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('mailboxes.telegram.save');

// Nostr
Route::get('/mailbox/settings/{id}/nostr', ['uses' => 'NostrController@mailboxSettings', 'middleware' => ['auth']])->name('mailboxes.nostr');
Route::post('/mailbox/settings/{id}/nostr', ['uses' => 'NostrController@mailboxSettingsSave', 'middleware' => ['auth']])->name('mailboxes.nostr.save');
Route::get('/customers/{id}/nostr', ['uses' => 'NostrController@customerKeys', 'middleware' => ['auth']])->name('customers.nostr');
Route::post('/customers/{id}/nostr', ['uses' => 'NostrController@customerKeysSave', 'middleware' => ['auth']])->name('customers.nostr.save');

// AI Assistant documentation
Route::get('/ai-assistant/documents', ['uses' => 'AiDocumentsController@index', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('ai.documents');
Route::post('/ai-assistant/documents', ['uses' => 'AiDocumentsController@action', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('ai.documents.action');
Route::post('/ai-assistant/customer-context/test', ['uses' => 'AiDraftsController@testCustomerContext', 'middleware' => ['auth', 'roles'], 'roles' => ['admin'], 'laroute' => true])->name('ai.customer_context.test');
// AI Assistant reply drafts
Route::post('/ai-assistant/conversations/{id}/draft-reply', 'AiDraftsController@store')->middleware(['auth', 'throttle:30,1'])->name('ai.drafts.store');
Route::get('/ai-assistant/draft-jobs/{id}', 'AiDraftsController@show')->middleware('auth')->name('ai.drafts.show');

// Users
Route::get('/users', ['uses' => 'UsersController@users', 'laroute' => true])->name('users');
Route::get('/users/wizard', 'UsersController@create')->name('users.create');
Route::post('/users/wizard', 'UsersController@createSave');
Route::get('/users/profile/{id}', 'UsersController@profile')->name('users.profile');
Route::post('/users/profile/{id}', 'UsersController@profileSave')->name('users.profile.save');
Route::post('/users/permissions/{id}', 'UsersController@permissionsSave');
Route::get('/users/permissions/{id}', 'UsersController@permissions')->name('users.permissions');
Route::post('/users/permissions/{id}', 'UsersController@permissionsSave')->name('users.permissions.save');
Route::get('/users/notifications/{id}', 'UsersController@notifications')->name('users.notifications');
Route::post('/users/notifications/{id}', 'UsersController@notificationsSave')->name('users.notifications.save');
Route::get('/users/api-keys/{id}', 'ApiKeysController@index')->middleware('password.confirm')->name('users.api_keys');
Route::post('/users/api-keys/{id}', 'ApiKeysController@action')->middleware('password.confirm')->name('users.api_keys.action');
Route::get('/users/security/{id}', 'UserSecurityController@show')->middleware('password.confirm')->name('users.security');
Route::post('/users/security/{id}/reset', 'UserSecurityController@reset')->middleware('password.confirm')->name('users.security.reset');
Route::post('/users/security/{id}/forget-devices', 'UserSecurityController@forgetDevices')->name('users.security.forget_devices');
Route::get('/users/password/{id}', 'UsersController@password')->name('users.password');
Route::post('/users/password/{id}', 'UsersController@passwordSave')->name('users.password.save');
Route::post('/users/ajax', ['uses' => 'UsersController@ajax', 'laroute' => true])->name('users.ajax');

// Conversations
Route::get('/conversation/{id}', ['uses' => 'ConversationsController@view', 'laroute' => true])->name('conversations.view');
Route::post('/conversation/ajax', ['uses' => 'ConversationsController@ajax', 'laroute' => true])->name('conversations.ajax');
Route::post('/attachments/{id}/delete', ['uses' => 'AttachmentsController@delete', 'laroute' => true])->name('attachments.delete');
Route::get('/attachments/{id}/email', 'AttachmentsController@email')->name('attachments.email');
Route::get('/thread/{thread_id}/attachments.zip', 'AttachmentsController@download')->name('attachments.download_all');
Route::post('/conversation/external-images', ['uses' => 'ExternalImagesController@ajax', 'laroute' => true])->name('conversations.external_images');
Route::post('/conversation/upload', ['uses' => 'ConversationsController@upload', 'laroute' => true])->middleware('throttle:100,1')->name('conversations.upload');
Route::get('/mailbox/{mailbox_id}/new-ticket', 'ConversationsController@create')->name('conversations.create');
Route::get('/mailbox/{mailbox_id}/clone-ticket/{from_thread_id}/{token}', 'ConversationsController@cloneConversation')->name('conversations.clone_conversation');
//Route::get('/conversation/draft/{id}', 'ConversationsController@draft')->name('conversations.draft');
Route::get('/conversation/ajax-html/{action}', ['uses' => 'ConversationsController@ajaxHtml', 'laroute' => true])->name('conversations.ajax_html');
Route::get('/search', 'ConversationsController@search')->name('conversations.search');
Route::get('/conversation/undo-reply/{thread_id}/{token}', 'ConversationsController@undoReply')->name('conversations.undo');
Route::get('/mailbox/{mailbox_id}/chats', 'ConversationsController@chats')->name('conversations.chats');

// Mailboxes
Route::get('/mailboxes', ['uses' => 'MailboxesController@mailboxes', 'laroute' => true])->name('mailboxes');
Route::get('/mailbox/new', 'MailboxesController@create')->name('mailboxes.create');
Route::post('/mailbox/new', 'MailboxesController@createSave');
Route::get('/mailbox/settings/{id}', 'MailboxesController@update')->name('mailboxes.update');
Route::post('/mailbox/settings/{id}', 'MailboxesController@updateSave')->name('mailboxes.update.save');
Route::get('/mailbox/permissions/{id}', 'MailboxesController@permissions')->name('mailboxes.permissions');
Route::post('/mailbox/permissions/{id}', 'MailboxesController@permissionsSave')->name('mailboxes.permissions.save');
Route::get('/mailbox/all/{folder_id?}', 'AllMailboxesController@view')->where('folder_id', '-?\d+')->name('mailboxes.all');
Route::get('/mailbox/{id}', 'MailboxesController@view')->name('mailboxes.view');
Route::get('/mailbox/{id}/{folder_id}', 'MailboxesController@view')->name('mailboxes.view.folder');
Route::get('/mailbox/connection-settings/{id}/outgoing', 'MailboxesController@connectionOutgoing')->name('mailboxes.connection');
Route::post('/mailbox/connection-settings/{id}/outgoing', 'MailboxesController@connectionOutgoingSave')->name('mailboxes.connection.save');
Route::get('/mailbox/connection-settings/{id}/incoming', 'MailboxesController@connectionIncoming')->name('mailboxes.connection.incoming');
Route::post('/mailbox/connection-settings/{id}/incoming', 'MailboxesController@connectionIncomingSave')->name('mailboxes.connection.incoming.save');
Route::get('/mailbox/saved-replies/{id}', 'SavedRepliesController@index')->name('mailboxes.saved_replies');
Route::get('/mailbox/saved-replies/{id}/new', 'SavedRepliesController@edit')->name('mailboxes.saved_replies.create');
Route::get('/mailbox/saved-replies/{id}/{saved_reply_id}/edit', 'SavedRepliesController@edit')->name('mailboxes.saved_replies.edit');
Route::post('/mailbox/saved-replies/{id}/save', 'SavedRepliesController@save')->name('mailboxes.saved_replies.save');
Route::post('/mailbox/saved-replies/{id}/{saved_reply_id}/delete', 'SavedRepliesController@delete')->name('mailboxes.saved_replies.delete');
Route::post('/saved-replies/ajax', ['uses' => 'SavedRepliesController@ajax', 'laroute' => true])->name('saved_replies.ajax');
Route::get('/mailbox/settings/{id}/auto-reply', 'MailboxesController@autoReply')->name('mailboxes.auto_reply');
Route::post('/mailbox/settings/{id}/auto-reply', 'MailboxesController@autoReplySave')->name('mailboxes.auto_reply.save');
Route::post('/mailbox/ajax', ['uses' => 'MailboxesController@ajax', 'laroute' => true])->name('mailboxes.ajax');
Route::get('/mailbox/oauth/{id}/{in_out}/{provider}', ['uses' => 'MailboxesController@oauth'])->name('mailboxes.oauth');
Route::get('/mailbox/oauth', ['uses' => 'MailboxesController@oauth'])->name('mailboxes.oauth_callback');
Route::get('/mailbox/oauth-disconnect/{id}/{in_out}/{provider}', ['uses' => 'MailboxesController@oauthDisconnect'])->name('mailboxes.oauth_disconnect');

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
Route::get('/modules/list', ['uses' => 'ModulesController@modules', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('modules');
Route::post('/modules/ajax', ['uses' => 'ModulesController@ajax', 'laroute' => true, 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('modules.ajax');

// System
Route::get('/system/status', ['uses' => 'SystemController@status', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('system');
Route::get('/system/tools', ['uses' => 'SystemController@tools', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('system.tools');
Route::post('/system/tools', ['uses' => 'SystemController@toolsExecute', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('system.tools.action');
Route::post('/system/ajax', ['uses' => 'SystemController@ajax', 'laroute' => true, 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('system.ajax');
Route::post('/system/action', ['uses' => 'SystemController@action', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('system.action');
Route::get('/system/ajax-html/{action}/{param?}', ['uses' => 'SystemController@ajaxHtml', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('system.ajax_html');

// Uploads
Route::post('/uploads/upload', ['uses' => 'SecureController@upload', 'laroute' => true])->middleware('throttle:100,1')->name('uploads.upload');