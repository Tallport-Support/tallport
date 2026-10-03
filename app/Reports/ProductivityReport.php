<?php

namespace App\Reports;

use App\User;

/**
 * What agents did: replies, closed conversations, and how long customers
 * waited for a response and a resolution. Can be filtered by user.
 */
class ProductivityReport extends Report
{
    /**
     * A reply this long after closing still resolved the conversation
     * (Send & Close saves the reply first).
     */
    const CLOSE_MARGIN = 60;

    public function hasUserFilter()
    {
        return true;
    }

    public function data($chart = [])
    {
        $replies = $this->replies()->pluck('threads.created_at')->all();
        $replies_prev = $this->replies(true)->pluck('threads.created_at')->all();
        $closed = $this->closed()->get(['conversations.id', 'conversations.created_at', 'conversations.closed_at']);
        $closed_prev = $this->closed(true)->get(['conversations.id', 'conversations.created_at', 'conversations.closed_at']);
        $replies_to_resolve = $this->repliesToResolve($closed);
        $replies_to_resolve_prev = $this->repliesToResolve($closed_prev);

        $data = [];
        $data['metrics'] = [
            'customers_helped' => self::metric($this->countCustomersHelped(), $this->countCustomersHelped(true)),
            'replies'          => self::metric(count($replies), count($replies_prev)),
            'replies_day'      => self::metric($this->perDay(count($replies)), $this->perDay(count($replies_prev))),
            'closed'           => self::metric(count($closed), count($closed_prev)),
            'rfr'              => self::metric(
                count(array_filter($replies_to_resolve, fn ($count) => $count == 1)),
                count(array_filter($replies_to_resolve_prev, fn ($count) => $count == 1))
            ),
        ];

        $types = ['replies' => __('Replies Sent'), 'closed' => __('Closed')];
        $type = ($chart['type'] ?? '') == 'closed' ? 'closed' : 'replies';
        $data['chart'] = $type == 'closed'
            ? $this->chart($chart, $types, $closed->pluck('closed_at')->all(), $closed_prev->pluck('closed_at')->all())
            : $this->chart($chart, $types, $replies, $replies_prev);
        $data['chart']['type'] = $type;

        $data['table_first_response_time'] = self::timeTable($this->responseTimes(true), $this->responseTimes(true, true));
        $data['table_response_time'] = self::timeTable($this->responseTimes(false), $this->responseTimes(false, true));
        $data['table_resolution_time'] = self::timeTable(
            $closed->map(fn ($conversation) => self::resolutionTime($conversation))->all(),
            $closed_prev->map(fn ($conversation) => self::resolutionTime($conversation))->all()
        );
        $data['table_replies_to_resolve'] = self::countTable(array_values($replies_to_resolve), array_values($replies_to_resolve_prev));
        $data['table_users'] = $this->tableUsers();

        return $data;
    }

    public function countCustomersHelped($prev = false)
    {
        return $this->replies($prev)->distinct()->count('conversations.customer_id');
    }

    /**
     * Seconds the customer waited for the first reply (or for every reply).
     */
    public function responseTimes($first, $prev = false)
    {
        $query = $this->recordedReplies($prev)->whereNotNull(Replies::TABLE.'.response_time');
        if ($first) {
            $query->where(Replies::TABLE.'.first', true);
        }

        return $query->pluck(Replies::TABLE.'.response_time')->map('intval')->all();
    }

    public static function resolutionTime($conversation)
    {
        return max(0, $conversation->closed_at->getTimestamp() - $conversation->created_at->getTimestamp());
    }

    /**
     * Replies until each conversation was closed, by conversation ID
     * (those without replies left out).
     */
    public function repliesToResolve($closed)
    {
        $counts = [];
        foreach ($closed->chunk(1000) as $chunk) {
            $closed_at = $chunk->pluck('closed_at', 'id');
            $replies = \DB::table(Replies::TABLE)->whereIn('conversation_id', $chunk->pluck('id'))->get(['conversation_id', 'replied_at']);
            foreach ($replies as $reply) {
                $until = $closed_at[$reply->conversation_id]->getTimestamp() + self::CLOSE_MARGIN;
                if (strtotime($reply->replied_at.' UTC') <= $until) {
                    $counts[$reply->conversation_id] = ($counts[$reply->conversation_id] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    /**
     * How many conversations needed 1, 2, 3, 4 or 5+ replies, with the
     * average.
     */
    public static function countTable(array $values, array $prev_values)
    {
        $rows = [];
        foreach ([1, 2, 3, 4, 5] as $number) {
            $in_row = fn ($value) => $number == 5 ? $value >= 5 : $value == $number;
            $count = count(array_filter($values, $in_row));
            $percent = $values ? (int) round($count * 100 / count($values)) : 0;
            $prev_percent = $prev_values ? (int) round(count(array_filter($prev_values, $in_row)) * 100 / count($prev_values)) : 0;
            if ($count) {
                $rows[] = [
                    'title'   => $number == 5 ? '5+' : (string) $number,
                    'count'   => $count,
                    'percent' => $percent,
                    'change'  => $prev_values ? $percent - $prev_percent : null,
                ];
            }
        }

        return [
            'rows'         => $rows,
            'count'        => count($values),
            'average'      => $values ? round(array_sum($values) / count($values), 1) : null,
            'average_prev' => $prev_values ? round(array_sum($prev_values) / count($prev_values), 1) : null,
        ];
    }

    /**
     * Each user: replies, closed, customers helped, median first response
     * and response times.
     */
    public function tableUsers()
    {
        $prefix = \DB::getTablePrefix();
        $replies = $this->replies()->groupBy('threads.created_by_user_id')
            ->get([\DB::raw($prefix.'threads.created_by_user_id AS user_id'), \DB::raw('COUNT(*) AS replies'), \DB::raw('COUNT(DISTINCT '.$prefix.'conversations.customer_id) AS customers')])
            ->keyBy('user_id');
        $closed = $this->closed()->whereNotNull('conversations.closed_by_user_id')->groupBy('conversations.closed_by_user_id')
            ->get([\DB::raw($prefix.'conversations.closed_by_user_id AS user_id'), \DB::raw('COUNT(*) AS closed')])
            ->pluck('closed', 'user_id');
        $times = $this->recordedReplies()->whereNotNull(Replies::TABLE.'.response_time')
            ->get([Replies::TABLE.'.user_id', Replies::TABLE.'.response_time', Replies::TABLE.'.first'])
            ->groupBy('user_id');

        $user_ids = $replies->keys()->merge($closed->keys())->unique();
        $table = [];
        foreach (User::whereIn('id', $user_ids)->get() as $user) {
            $user_times = $times[$user->id] ?? collect();
            $table[] = [
                'user_id'             => $user->id,
                'name'                => $user->getFullName(),
                'replies'             => (int) ($replies[$user->id]->replies ?? 0),
                'closed'              => (int) ($closed[$user->id] ?? 0),
                'customers_helped'    => (int) ($replies[$user->id]->customers ?? 0),
                'first_response_time' => self::median($user_times->where('first', true)->pluck('response_time')->map('intval')->all()),
                'response_time'       => self::median($user_times->pluck('response_time')->map('intval')->all()),
            ];
        }
        usort($table, fn ($a, $b) => [$b['replies'], $b['closed']] <=> [$a['replies'], $a['closed']]);

        return $table;
    }
}
