<?php

namespace App\Reports;

use App\Conversation;
use App\Mailbox;
use App\Thread;
use App\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * What reports share: the filters (period in the viewer's timezone,
 * mailbox, type, user), the previous period of the same length, the
 * conversations and threads they cover, charts and time tables.
 */
abstract class Report
{
    /**
     * Rows in top lists.
     */
    const TOP = 20;

    const PERIODS = ['today', 'yesterday', 'last_7', 'last_30', 'this_month', 'last_month', 'this_year', 'last_year', 'custom'];

    const DEFAULT_PERIOD = 'last_30';

    /**
     * Time table rows: up to (seconds) => title.
     */
    const TIME_ROWS = [
        900    => ['< 15', 'min'],
        1800   => ['15-30', 'min'],
        3600   => ['30-60', 'min'],
        7200   => ['1-2', 'hours'],
        10800  => ['2-3', 'hours'],
        21600  => ['3-6', 'hours'],
        43200  => ['6-12', 'hours'],
        86400  => ['12-24', 'hours'],
        172800 => ['1-2', 'days'],
        604800 => ['2-7', 'days'],
        0      => ['> 7', 'days'],
    ];

    public $viewer;

    /**
     * period, from, to (Y-m-d in the viewer's timezone), mailbox, type, user.
     */
    public $filters = [];

    public $timezone;

    /**
     * The period and the previous one, as local dates.
     */
    public $from;
    public $to;
    public $prev_from;
    public $prev_to;

    /**
     * Days in the period.
     */
    public $days;

    /**
     * Mailboxes covered.
     */
    public $mailbox_ids = [];

    public function __construct(User $viewer, array $input = [])
    {
        $this->viewer = $viewer;
        $this->timezone = $viewer->timezone ?: config('app.timezone');
        $today = CarbonImmutable::now($this->timezone)->startOfDay();

        $period = in_array($input['period'] ?? '', self::PERIODS) ? $input['period'] : self::DEFAULT_PERIOD;
        if ($period == 'custom') {
            $from = self::parseDate($input['from'] ?? '', $this->timezone) ?: $today->subDays(29);
            $to = self::parseDate($input['to'] ?? '', $this->timezone) ?: $today;
            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }
        } else {
            [$from, $to] = self::periodDates($period, $today);
        }
        $this->from = $from;
        $this->to = $to;
        $this->days = (int) $from->diffInDays($to) + 1;
        $this->prev_to = $from->subDay();
        $this->prev_from = $from->subDays($this->days);

        $mailbox_ids = $viewer->mailboxesIdsCanView();
        $mailbox = (int) ($input['mailbox'] ?? 0);
        $this->mailbox_ids = in_array($mailbox, $mailbox_ids) ? [$mailbox] : $mailbox_ids;

        $type = (string) ($input['type'] ?? '');
        $user = (int) ($input['user'] ?? 0);
        $this->filters = [
            'period'  => $period,
            'from'    => $from->format('Y-m-d'),
            'to'      => $to->format('Y-m-d'),
            'mailbox' => in_array($mailbox, $mailbox_ids) ? $mailbox : null,
            'type'    => array_key_exists($type, self::types()) ? $type : null,
            'user'    => $user && $this->hasUserFilter() ? $user : null,
        ];
    }

    /**
     * The report's numbers, charts and tables.
     */
    abstract public function data($chart = []);

    /**
     * Whether the report can be filtered by user.
     */
    public function hasUserFilter()
    {
        return false;
    }

    public static function parseDate($date, $timezone)
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $date, $timezone)->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function periodDates($period, CarbonImmutable $today)
    {
        switch ($period) {
            case 'today':
                return [$today, $today];
            case 'yesterday':
                return [$today->subDay(), $today->subDay()];
            case 'last_7':
                return [$today->subDays(6), $today];
            case 'this_month':
                return [$today->startOfMonth(), $today];
            case 'last_month':
                return [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()];
            case 'this_year':
                return [$today->startOfYear(), $today];
            case 'last_year':
                return [$today->subYear()->startOfYear(), $today->subYear()->endOfYear()->startOfDay()];
            case 'last_30':
            default:
                return [$today->subDays(29), $today];
        }
    }

    public static function periodNames()
    {
        return [
            'today'      => __('Today'),
            'yesterday'  => __('Yesterday'),
            'last_7'     => __('Last 7 Days'),
            'last_30'    => __('Last 30 Days'),
            'this_month' => __('This Month'),
            'last_month' => __('Last Month'),
            'this_year'  => __('This Year'),
            'last_year'  => __('Last Year'),
            'custom'     => __('Custom'),
        ];
    }

    /**
     * How conversations came in: email, phone, or a channel (channel-<code>).
     */
    public static function types()
    {
        $types = [
            Conversation::TYPE_EMAIL => __('Email'),
            Conversation::TYPE_PHONE => __('Phone'),
        ];
        foreach (\Eventy::filter('channels.list', []) as $channel => $channel_name) {
            $types['channel-'.$channel] = $channel_name;
        }

        return $types;
    }

    /**
     * Mailboxes the viewer can choose.
     */
    public function mailboxes()
    {
        return $this->viewer->mailboxesCanView();
    }

    /**
     * Users the viewer can choose: those with access to the viewer's mailboxes.
     */
    public function users()
    {
        $mailbox_ids = $this->viewer->mailboxesIdsCanView();

        return User::nonDeleted()
            ->where('type', '!=', User::TYPE_ROBOT)
            ->where(function ($query) use ($mailbox_ids) {
                $query->where('role', User::ROLE_ADMIN)
                    ->orWhereIn('id', \DB::table('mailbox_user')->whereIn('mailbox_id', $mailbox_ids)->select('user_id'));
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
    }

    /**
     * Start and end of the period (or the previous one), UTC.
     */
    public function range($prev = false)
    {
        $from = $prev ? $this->prev_from : $this->from;
        $to = $prev ? $this->prev_to : $this->to;

        return [
            $from->utc()->format('Y-m-d H:i:s'),
            $to->endOfDay()->utc()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Conversations in the mailboxes and of the type, not spam or deleted;
     * with $date_column in the period.
     */
    public function conversations($prev = false, $date_column = 'conversations.created_at')
    {
        $query = Conversation::query();
        $this->scope($query);
        if ($date_column) {
            $query->whereBetween($date_column, $this->range($prev));
        }

        return $query;
    }

    /**
     * Threads of those conversations, created in the period.
     */
    public function threads($type, $prev = false)
    {
        $query = Thread::join('conversations', 'conversations.id', '=', 'threads.conversation_id')
            ->where('threads.type', $type)
            ->where('threads.state', Thread::STATE_PUBLISHED)
            ->whereBetween('threads.created_at', $this->range($prev));
        $this->scope($query);

        return $query;
    }

    /**
     * Agents' replies to customers in the period (by the user, if chosen).
     */
    public function replies($prev = false)
    {
        $query = $this->threads(Thread::TYPE_MESSAGE, $prev)
            ->whereNotNull('threads.created_by_user_id')
            ->whereNotIn('threads.created_by_user_id', User::where('type', User::TYPE_ROBOT)->select('id'));
        if ($this->filters['user']) {
            $query->where('threads.created_by_user_id', $this->filters['user']);
        }

        return $query;
    }

    /**
     * Recorded replies (App\Reports\Replies) in the period.
     */
    public function recordedReplies($prev = false)
    {
        $query = \DB::table(Replies::TABLE)
            ->join('conversations', 'conversations.id', '=', Replies::TABLE.'.conversation_id')
            ->whereBetween(Replies::TABLE.'.replied_at', $this->range($prev));
        $this->scope($query);
        if ($this->filters['user']) {
            $query->where(Replies::TABLE.'.user_id', $this->filters['user']);
        }

        return $query;
    }

    /**
     * Conversations closed in the period (by the user, if chosen).
     */
    public function closed($prev = false)
    {
        $query = $this->conversations($prev, 'conversations.closed_at')
            ->where('conversations.status', Conversation::STATUS_CLOSED);
        if ($this->filters['user']) {
            $query->where('conversations.closed_by_user_id', $this->filters['user']);
        }

        return $query;
    }

    protected function scope($query)
    {
        $query->whereIn('conversations.mailbox_id', $this->mailbox_ids ?: [0])
            ->where('conversations.state', Conversation::STATE_PUBLISHED)
            ->where('conversations.status', '!=', Conversation::STATUS_SPAM);
        $type = (string) ($this->filters['type'] ?? '');
        if (str_starts_with($type, 'channel-')) {
            $query->where('conversations.channel', (int) substr($type, 8));
        } elseif ($type !== '') {
            $query->where('conversations.type', (int) $type)->whereNull('conversations.channel');
        }

        return $query;
    }

    /**
     * Change from the previous value in percent; null without one.
     */
    public static function change($value, $prev_value)
    {
        if (!$prev_value) {
            return null;
        }

        return (int) round(($value - $prev_value) * 100 / $prev_value);
    }

    /**
     * An average per day: one decimal, two below 1.
     */
    public function perDay($count)
    {
        $value = $count / $this->days;

        return round($value, $value < 1 ? 2 : 1);
    }

    public static function metric($value, $prev_value)
    {
        return ['value' => $value, 'change' => self::change($value, $prev_value)];
    }

    public static function median(array $values)
    {
        if (!$values) {
            return null;
        }
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 ? $values[$middle] : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }

    /**
     * "2 d 3 h", "3 h 5 min", "12 min".
     */
    public static function duration($seconds)
    {
        if ($seconds === null) {
            return '–';
        }
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        if ($days) {
            return trim(__(':value d', ['value' => $days]).($hours ? ' '.__(':value h', ['value' => $hours]) : ''));
        }
        if ($hours) {
            return trim(__(':value h', ['value' => $hours]).($minutes ? ' '.__(':value min', ['value' => $minutes]) : ''));
        }

        return __(':value min', ['value' => $minutes]);
    }

    /**
     * How many of the times (seconds) fall in each row, with the median.
     */
    public static function timeTable(array $values, array $prev_values)
    {
        $rows = [];
        $from = 0;
        foreach (self::TIME_ROWS as $to => [$value, $unit]) {
            $in_row = function ($seconds) use ($from, $to) {
                return $seconds >= $from && (!$to || $seconds < $to);
            };
            $count = count(array_filter($values, $in_row));
            $prev_count = count(array_filter($prev_values, $in_row));
            $percent = $values ? (int) round($count * 100 / count($values)) : 0;
            $prev_percent = $prev_values ? (int) round($prev_count * 100 / count($prev_values)) : 0;
            if ($count) {
                $rows[] = [
                    'title'   => [
                        'min'   => __(':value min', ['value' => $value]),
                        'hours' => __(':value hours', ['value' => $value]),
                        'days'  => __(':value days', ['value' => $value]),
                    ][$unit],
                    'count'   => $count,
                    'percent' => $percent,
                    'change'  => $prev_values ? $percent - $prev_percent : null,
                ];
            }
            $from = $to;
        }

        return [
            'rows'        => $rows,
            'count'       => count($values),
            'median'      => self::median($values),
            'median_prev' => self::median($prev_values),
        ];
    }

    /**
     * How the chart can be grouped: day, week, month.
     */
    public function groupBys()
    {
        $group_bys = [];
        if ($this->days <= 92) {
            $group_bys[] = 'd';
        }
        if ($this->days >= 14) {
            $group_bys[] = 'w';
        }
        if ($this->days >= 60) {
            $group_bys[] = 'm';
        }

        return $group_bys;
    }

    /**
     * A chart of the period and the previous one: $dates and $prev_dates
     * (UTC) counted by day, week or month.
     */
    public function chart($chart, $types, $dates, $prev_dates)
    {
        $group_bys = $this->groupBys();
        $group_by = in_array($chart['group_by'] ?? '', $group_bys) ? $chart['group_by'] : $group_bys[0];

        // Each day of the period in its group.
        $labels = [];
        $day_groups = [];
        $label = null;
        for ($i = 0; $i < $this->days; $i++) {
            $day = $this->from->addDays($i);
            if ($group_by == 'm') {
                $key = $day->format('Y-m');
                $title = \App\User::dateFormat($day, 'M Y', $this->viewer, false, false);
            } elseif ($group_by == 'w') {
                $key = intdiv($i, 7);
                $title = \App\User::dateFormat($day, 'M j', $this->viewer, false, false);
            } else {
                $key = $i;
                $title = \App\User::dateFormat($day, 'M j', $this->viewer, false, false);
            }
            if ($key !== $label) {
                $labels[] = $title;
                $label = $key;
            }
            $day_groups[$i] = count($labels) - 1;
        }

        return [
            'type'      => in_array($chart['type'] ?? '', array_keys($types)) ? $chart['type'] : array_key_first($types),
            'types'     => $types,
            'group_by'  => $group_by,
            'group_bys' => $group_bys,
            'labels'    => $labels,
            'datasets'  => [
                ['label' => __('This Period'), 'data' => $this->countByGroup($dates, $this->from, $day_groups, count($labels))],
                ['label' => __('Previous Period'), 'data' => $this->countByGroup($prev_dates, $this->prev_from, $day_groups, count($labels))],
            ],
        ];
    }

    protected function countByGroup($dates, CarbonImmutable $start, array $day_groups, $count)
    {
        $data = array_fill(0, $count, 0);
        foreach ($dates as $date) {
            $day = (int) $start->diffInDays(Carbon::parse($date, 'UTC')->setTimezone($this->timezone)->startOfDay());
            if (isset($day_groups[$day])) {
                $data[$day_groups[$day]]++;
            }
        }

        return $data;
    }

    /**
     * Mailbox names by ID.
     */
    public function mailboxNames()
    {
        return Mailbox::whereIn('id', $this->mailbox_ids)->pluck('name', 'id');
    }
}
