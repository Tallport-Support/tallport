<?php

/*
|--------------------------------------------------------------------------
| REST API (App\Api): /api/..., with an API key
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\Api;

Route::get('/conversations', [Api\ConversationsController::class, 'index'])->name('api.conversations.index');
Route::post('/conversations', [Api\ConversationsController::class, 'store'])->name('api.conversations.store');
Route::get('/conversations/{id}', [Api\ConversationsController::class, 'show'])->name('api.conversations.show');
Route::put('/conversations/{id}', [Api\ConversationsController::class, 'update'])->name('api.conversations.update');
Route::delete('/conversations/{id}', [Api\ConversationsController::class, 'destroy'])->name('api.conversations.destroy');
Route::post('/conversations/{id}/threads', [Api\ConversationsController::class, 'storeThread'])->name('api.threads.store');

Route::get('/customers', [Api\CustomersController::class, 'index'])->name('api.customers.index');
Route::post('/customers', [Api\CustomersController::class, 'store'])->name('api.customers.store');
Route::get('/customers/{id}', [Api\CustomersController::class, 'show'])->name('api.customers.show');
Route::put('/customers/{id}', [Api\CustomersController::class, 'update'])->name('api.customers.update');

Route::get('/users', [Api\UsersController::class, 'index'])->name('api.users.index');
Route::post('/users', [Api\UsersController::class, 'store'])->name('api.users.store');
Route::get('/users/me', [Api\UsersController::class, 'me'])->name('api.users.me');
Route::get('/users/{id}', [Api\UsersController::class, 'show'])->name('api.users.show');
Route::delete('/users/{id}', [Api\UsersController::class, 'destroy'])->name('api.users.destroy');

Route::get('/mailboxes', [Api\MailboxesController::class, 'index'])->name('api.mailboxes.index');
Route::get('/mailboxes/{id}/folders', [Api\MailboxesController::class, 'folders'])->name('api.mailboxes.folders');

Route::get('/webhooks', [Api\WebhooksController::class, 'index'])->name('api.webhooks.index');
Route::post('/webhooks', [Api\WebhooksController::class, 'store'])->name('api.webhooks.store');
Route::delete('/webhooks/{id}', [Api\WebhooksController::class, 'destroy'])->name('api.webhooks.destroy');

Route::get('/reports/{name}', [Api\ReportsController::class, 'show'])->name('api.reports.show');

// For modules Tallport doesn't have: an error saying so.
Route::get('/tags', [Api\ConversationsController::class, 'moduleMissing'])->name('api.tags.index');
Route::put('/conversations/{id}/tags', [Api\ConversationsController::class, 'moduleMissing'])->name('api.conversations.tags');
Route::put('/conversations/{id}/custom_fields', [Api\ConversationsController::class, 'moduleMissing'])->name('api.conversations.custom_fields');
Route::get('/mailboxes/{id}/custom_fields', [Api\ConversationsController::class, 'moduleMissing'])->name('api.mailboxes.custom_fields');
Route::put('/customers/{id}/customer_fields', [Api\ConversationsController::class, 'moduleMissing'])->name('api.customers.customer_fields');
Route::get('/conversations/{id}/timelogs', [Api\ConversationsController::class, 'moduleMissing'])->name('api.conversations.timelogs');
Route::post('/conversations/{id}/timelogs', [Api\ConversationsController::class, 'moduleMissing'])->name('api.conversations.timelogs.store');
Route::get('/timelogs', [Api\ConversationsController::class, 'moduleMissing'])->name('api.timelogs.index');

Route::options('/{any}', function () {
    return response()->json(null);
})->where('any', '.*')->name('api.options');
