<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users' passkeys (laravel/passkeys).
 */
class CreatePasskeysTable extends Migration
{
    public function up()
    {
        Schema::create('passkeys', function (Blueprint $table) {
            $table->bigIncrements('id');
            // users.id is an unsigned integer.
            $table->unsignedInteger('user_id')->index();
            $table->string('name');
            $table->string('credential_id')->unique();
            $table->json('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('passkeys');
    }
}
