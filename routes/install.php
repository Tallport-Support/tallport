<?php

/*
|--------------------------------------------------------------------------
| Installer Routes
|--------------------------------------------------------------------------
|
| The web installer (/install). App\Http\Middleware\CanInstall closes it
| once the application is installed.
|
*/

Route::group(['prefix' => 'install', 'as' => 'LaravelInstaller::', 'middleware' => [\App\Http\Middleware\CanInstall::class]], function () {
    Route::get('/', 'WelcomeController@welcome')->name('welcome');
    Route::get('environment', 'EnvironmentController@environmentMenu')->name('environment');
    Route::get('environment/wizard', 'EnvironmentController@environmentWizard')->name('environmentWizard');
    Route::post('environment/saveWizard', 'EnvironmentController@saveWizard')->name('environmentSaveWizard');
    Route::get('environment/classic', 'EnvironmentController@environmentClassic')->name('environmentClassic');
    Route::post('environment/saveClassic', 'EnvironmentController@saveClassic')->name('environmentSaveClassic');
    Route::get('requirements', 'RequirementsController@requirements')->name('requirements');
    Route::get('permissions', 'PermissionsController@permissions')->name('permissions');
    Route::get('database', 'DatabaseController@database')->name('database');
    Route::get('final', 'FinalController@finish')->name('final');
});
