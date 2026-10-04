<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user's own reply preferences (users/preferences): the status a reply leaves
 * the conversation in (null: as the mailbox is set) and where the user goes
 * after sending (null: the next active conversation). Where to go was set per
 * mailbox (mailbox_user.after_send, Default Redirect): each user keeps the
 * choice made most often.
 */
class AddReplyPreferencesToUsersTable extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'reply_status')) {
                $table->unsignedTinyInteger('reply_status')->nullable();
            }
            if (!Schema::hasColumn('users', 'after_send')) {
                $table->unsignedTinyInteger('after_send')->nullable();
            }
        });

        $choices = \DB::table('mailbox_user')->whereNotNull('after_send')
            ->select('user_id', 'after_send', \DB::raw('count(*) as uses'))
            ->groupBy('user_id', 'after_send')
            ->orderBy('uses', 'desc')
            ->get();
        foreach ($choices->groupBy('user_id') as $user_id => $user_choices) {
            $after_send = (int) $user_choices->first()->after_send;
            if ($after_send && $after_send != \App\MailboxUser::AFTER_SEND_NEXT) {
                \DB::table('users')->where('id', $user_id)->whereNull('after_send')->update(['after_send' => $after_send]);
            }
        }
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['reply_status', 'after_send']);
        });
    }
}
