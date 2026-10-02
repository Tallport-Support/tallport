<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Remember this device" for two-factor authentication (App\Auth\TrustedDevices).
 */
class CreateTwoFactorTrustedDevicesTable extends Migration
{
    public function up()
    {
        Schema::create('two_factor_trusted_devices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id')->index();
            // SHA-256 of the token in the browser's cookie.
            $table->string('token', 64)->unique();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('two_factor_trusted_devices');
    }
}
