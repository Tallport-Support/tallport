<?php

/*
|--------------------------------------------------------------------------
| Open Routes
|--------------------------------------------------------------------------
|
| Here is where you can register open routes for your application. These
|
| Every time you change routes, run the following command to make them available in JS:
|     php artisan tallport:build
*/

// Download attachments
Route::get('/storage/attachment/{dir_1}/{dir_2}/{dir_3}/{file_name}', 'OpenController@downloadAttachment')->name('attachment.download');
// Open tracking
Route::get('/thread/read/{conversation_id}/{thread_id}/{hash}', 'OpenController@setThreadAsRead')->name('open_tracking.set_read');
// AI Assistant documentation pushed by websites (key per mailbox)
Route::post('/ai-assistant/api/documents', 'AiDocumentsController@api')->middleware('throttle:60,1')->name('ai.documents.api');
// Telegram bots' updates (per mailbox)
Route::post('/telegram/webhook/{mailbox_id}', 'TelegramController@webhook')->name('telegram.webhook');
// Nostr addresses (NIP-05), fetched by Nostr clients
Route::get('/.well-known/nostr.json', 'NostrController@nip05')->name('nostr.nip05');
// Web Cron
Route::get('/system/cron/{hash}', ['uses' => 'SystemController@cron'])->name('system.cron');