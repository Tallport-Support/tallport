<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * A message in a mailbox's team chat: one room per mailbox, for the users who
 * can see the mailbox (App\Livewire\TeamChat). Each user's last read message
 * is in team_chat_reads. The text is stored encrypted (the app key); it isn't
 * searched on the server.
 */
class TeamMessage extends Model
{
    const READS_TABLE = 'team_chat_reads';

    /**
     * A mention (@ and a name): dots inside the name, not at its end (a sentence's), and not an email address.
     */
    const MENTION = '/(?<![\w@])@((?>[\p{L}\p{N}_-]+(?:\.[\p{L}\p{N}_-]+)*))(?![@\p{L}\p{N}_-])/u';

    protected $guarded = ['id'];

    protected $casts = [
        'body' => 'encrypted',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function mailbox()
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class, 'team_message_id');
    }

    /**
     * The mailbox's room, gone: its messages, their files and who read what.
     */
    public static function deleteForMailbox($mailbox_id)
    {
        $ids = self::where('mailbox_id', $mailbox_id)->pluck('id')->all();
        foreach (array_chunk($ids, \Helper::IN_LIMIT) as $chunk) {
            Attachment::deleteForever(Attachment::whereIn('team_message_id', $chunk)->get());
        }
        self::where('mailbox_id', $mailbox_id)->delete();
        \DB::table(self::READS_TABLE)->where('mailbox_id', $mailbox_id)->delete();
    }

    /**
     * The room's members: the users who can see the mailbox, active ones.
     */
    public static function members(Mailbox $mailbox)
    {
        return $mailbox->usersHavingAccess()->filter(function ($user) {
            return $user->status == User::STATUS_ACTIVE && $user->type != User::TYPE_ROBOT;
        })->sortBy('first_name')->values();
    }

    public static function lastReadId($mailbox_id, $user_id)
    {
        return (int) \DB::table(self::READS_TABLE)->where('mailbox_id', $mailbox_id)->where('user_id', $user_id)->value('last_read_id');
    }

    /**
     * The user has read the room up to its newest message.
     */
    public static function markRead($mailbox_id, $user_id)
    {
        $last_id = (int) self::where('mailbox_id', $mailbox_id)->max('id');
        \DB::table(self::READS_TABLE)->updateOrInsert(['mailbox_id' => $mailbox_id, 'user_id' => $user_id], ['last_read_id' => $last_id]);
    }

    /**
     * Messages the user hasn't read, by others, per mailbox: [mailbox_id => count].
     */
    public static function unreadCounts(User $user, array $mailbox_ids)
    {
        if (!$mailbox_ids) {
            return [];
        }

        return self::query()
            ->leftJoin(self::READS_TABLE, function ($join) use ($user) {
                $join->on(self::READS_TABLE.'.mailbox_id', '=', 'team_messages.mailbox_id')->where(self::READS_TABLE.'.user_id', $user->id);
            })
            ->whereIn('team_messages.mailbox_id', $mailbox_ids)
            ->where('team_messages.user_id', '!=', $user->id)
            ->whereRaw('team_messages.id > COALESCE('.self::READS_TABLE.'.last_read_id, 0)')
            ->groupBy('team_messages.mailbox_id')
            ->pluck(\DB::raw('COUNT(*)'), 'team_messages.mailbox_id')
            ->map('intval')
            ->all();
    }

    /**
     * The users a text mentions (@first name, as the composer inserts them), among the members.
     */
    public static function mentioned($body, $members)
    {
        preg_match_all(self::MENTION, (string) $body, $matches);
        $names = array_map('mb_strtolower', $matches[1]);

        return collect($members)->filter(function ($member) use ($names) {
            return in_array(mb_strtolower(self::mentionName($member)), $names);
        })->values();
    }

    /**
     * How a user is mentioned: @ and their first name without spaces (else their email's name).
     */
    public static function mentionName(User $user)
    {
        $name = preg_replace('/[^\p{L}\p{N}._-]+/u', '', (string) $user->first_name);

        return $name !== '' ? $name : strstr((string) $user->email, '@', true);
    }

    /**
     * The text as HTML: escaped, #1234 linking to that conversation when the
     * user may see it, @mentions of members in bold, line breaks kept.
     */
    public static function html($body, Mailbox $mailbox, User $viewer, $members)
    {
        $mention_names = collect($members)->map(fn ($member) => mb_strtolower(self::mentionName($member)))->all();
        preg_match_all('/(?<![\w&])#(\d{1,10})\b/', (string) $body, $numbers);
        $conversations = $numbers[1] ? Conversation::whereIn(Conversation::numberFieldName(), array_unique($numbers[1]))->get()->filter(fn ($conversation) => $viewer->can('view', $conversation))->keyBy('number') : collect();

        $html = preg_replace_callback('/(?<![\w&])#(\d{1,10})\b|'.trim(self::MENTION, '/u').'/u', function ($match) use ($conversations, $mention_names) {
            if (!empty($match[1])) {
                $conversation = $conversations->get($match[1]);

                return $conversation ? '<a href="'.e($conversation->url()).'" title="'.e($conversation->getSubject()).'">#'.$match[1].'</a>' : e($match[0]);
            }
            if (in_array(mb_strtolower($match[2]), $mention_names)) {
                return '<strong class="team-mention">@'.e($match[2]).'</strong>';
            }

            return e($match[0]);
        }, e((string) $body));

        return nl2br($html);
    }
}
