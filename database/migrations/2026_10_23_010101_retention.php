<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retention (App\Retention\Retention): a customer's last contact (their latest
 * message), a conversation's expiry by retention (soft-deleted, restorable) and
 * restore by hand (its clock starts over), and legal holds on customers and
 * conversations.
 */
class Retention extends Migration
{
    public function up()
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('last_contact_at')->nullable()->index();
            $table->timestamp('retention_hold_at')->nullable();
            $table->unsignedInteger('retention_hold_by')->nullable();
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('expired_at')->nullable()->index();
            $table->timestamp('retention_reset_at')->nullable();
            $table->timestamp('retention_hold_at')->nullable();
            $table->unsignedInteger('retention_hold_by')->nullable();
        });

        // Customers' last contact so far: their latest message.
        $latest = \DB::table('threads')->where('type', 1)->whereNotNull('created_by_customer_id')
            ->groupBy('created_by_customer_id')->select('created_by_customer_id', \DB::raw('MAX(created_at) as last_contact_at'));
        if (in_array(\DB::getDriverName(), ['mysql', 'mariadb'])) {
            \DB::table('customers')->joinSub($latest, 'latest', 'latest.created_by_customer_id', '=', 'customers.id')
                ->update(['customers.last_contact_at' => \DB::raw('latest.last_contact_at')]);
        } else {
            foreach ($latest->get() as $row) {
                \DB::table('customers')->where('id', $row->created_by_customer_id)->update(['last_contact_at' => $row->last_contact_at]);
            }
        }
    }

    public function down()
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['last_contact_at', 'retention_hold_at', 'retention_hold_by']);
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['expired_at', 'retention_reset_at', 'retention_hold_at', 'retention_hold_by']);
        });
    }
}
