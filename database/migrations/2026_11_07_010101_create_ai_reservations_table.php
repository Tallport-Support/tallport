<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAiReservationsTable extends Migration
{
    public function up()
    {
        Schema::create('aiassistant_reservations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('mailbox_id')->nullable();
            $table->unsignedInteger('customer_id')->nullable();
            $table->unsignedInteger('tokens')->default(0);
            $table->unsignedSmallInteger('items')->default(0);
            $table->timestamp('created_at');

            $table->index(['mailbox_id', 'created_at']);
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('aiassistant_reservations');
    }
}
