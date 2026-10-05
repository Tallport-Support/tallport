<?php

namespace Tests\Feature;

use App\Api\ApiKey;
use App\Conversation;
use App\Livewire\ReportResults;
use App\Reports\ConversationsReport;
use App\Reports\ProductivityReport;
use App\Reports\Replies;
use App\Reports\Report;
use App\Thread;
use App\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * Reports: conversations and productivity, with response times recorded
 * per reply.
 */
class ReportsTest extends FeatureTestCase
{
    protected $admin;
    protected $agent;
    protected $support;
    protected $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin(['timezone' => 'UTC']);
        $this->agent = $this->createUser(['first_name' => 'Robin', 'last_name' => 'Reply', 'timezone' => 'UTC']);
        $this->support = $this->createMailbox([$this->agent], ['name' => 'Support']);
        $this->sales = $this->createMailbox([], ['name' => 'Sales']);
    }

    /**
     * A conversation from Casey received at $at.
     */
    protected function conversation($mailbox, Carbon $at, $from = 'casey@customer.example.org')
    {
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => $from, 'to' => $mailbox->email, 'subject' => 'Question']));
        $conversation = Conversation::where('mailbox_id', $mailbox->id)->orderBy('id', 'desc')->first();
        Conversation::where('id', $conversation->id)->update(['created_at' => $at]);
        Thread::where('conversation_id', $conversation->id)->update(['created_at' => $at]);

        return $conversation->fresh();
    }

    /**
     * A customer message (no $user) or a user's reply at $at.
     */
    protected function thread(Conversation $conversation, Carbon $at, ?User $user = null)
    {
        $thread = Thread::where('conversation_id', $conversation->id)->where('type', Thread::TYPE_CUSTOMER)->first()->replicate();
        if ($user) {
            $thread->type = Thread::TYPE_MESSAGE;
            $thread->created_by_user_id = $user->id;
            $thread->created_by_customer_id = null;
        }
        $thread->message_id = null;
        $thread->created_at = $at;
        $thread->save();

        return $thread;
    }

    protected function close(Conversation $conversation, Carbon $at, User $user)
    {
        Conversation::where('id', $conversation->id)->update([
            'status' => Conversation::STATUS_CLOSED, 'closed_at' => $at, 'closed_by_user_id' => $user->id,
        ]);
    }

    public function testResponseTimes()
    {
        $start = Carbon::now('UTC')->subDays(2)->setTime(9, 0);
        $conversation = $this->conversation($this->support, $start);
        $this->thread($conversation, $start->copy()->addMinutes(10));
        $first = $this->thread($conversation, $start->copy()->addHour(), $this->agent);
        $this->thread($conversation, $start->copy()->addHours(2));
        $second = $this->thread($conversation, $start->copy()->addMinutes(150), $this->agent);
        $follow_up = $this->thread($conversation, $start->copy()->addHours(3), $this->agent);

        Replies::update([$conversation->id]);

        $rows = \DB::table(Replies::TABLE)->where('conversation_id', $conversation->id)->orderBy('replied_at')->get();
        $this->assertSame([$first->id, $second->id, $follow_up->id], $rows->pluck('thread_id')->map('intval')->all());
        $this->assertSame(3600, (int) $rows[0]->response_time, 'From the first message waiting.');
        $this->assertTrue((bool) $rows[0]->first);
        $this->assertSame(1800, (int) $rows[1]->response_time);
        $this->assertFalse((bool) $rows[1]->first);
        $this->assertNull($rows[2]->response_time, 'Nobody was waiting.');

        // Done again when it changes, and by the command (for what was missed).
        $second->delete();
        Replies::flush();
        $this->assertSame(2, \DB::table(Replies::TABLE)->where('conversation_id', $conversation->id)->count());
        \DB::table(Replies::TABLE)->delete();
        $this->artisan('tallport:report-replies', ['--rebuild' => true])->assertExitCode(0);
        $this->assertSame(2, \DB::table(Replies::TABLE)->where('conversation_id', $conversation->id)->count());
    }

    public function testConversationsReport()
    {
        $today = Carbon::now('UTC')->startOfDay();
        $this->conversation($this->support, $today->copy()->subDays(1)->setTime(10, 0));
        $this->conversation($this->support, $today->copy()->subDays(2)->setTime(10, 0));
        $this->conversation($this->sales, $today->copy()->subDays(2)->setTime(11, 0), 'sam@customer.example.org');
        // The previous 7 days.
        $this->conversation($this->sales, $today->copy()->subDays(10)->setTime(11, 0), 'sam@customer.example.org');

        $data = (new ConversationsReport($this->admin, ['period' => 'last_7']))->data();
        $this->assertSame(['value' => 3, 'change' => 200], $data['metrics']['new']);
        $this->assertSame(3, $data['metrics']['messages']['value']);
        $this->assertSame(2, $data['metrics']['customers']['value']);
        $this->assertSame('casey@customer.example.org', $data['table_customers'][0]['email']);
        $this->assertSame(2, $data['table_customers'][0]['messages']);
        $this->assertSame(['Sales', 'Support'], array_column($data['table_mailboxes'], 'name'));
        $this->assertSame([1, 2], array_column($data['table_mailboxes'], 'new'));
        $this->assertSame(3, array_sum($data['chart']['datasets'][0]['data']));
        $this->assertSame(1, array_sum($data['chart']['datasets'][1]['data']));

        Livewire::withoutLazyLoading();
        $this->actingAs($this->admin)->get(route('reports.conversations', ['period' => 'last_7']))->assertOk()
            ->assertSee('Conversations Report')->assertSee('<svg class="rpt-chart"', false)
            ->assertSee('Most Active Customers')->assertSee('casey@customer.example.org')
            ->assertSee(route('reports.productivity'), false);

        // Users need the permission, and see their mailboxes.
        $this->actingAs($this->agent)->get(route('reports.conversations'))->assertForbidden();
        $this->actingAs($this->agent)->get('/?dashboard=1')->assertDontSee(route('reports.conversations'), false);
        $this->agent->permissions = [User::PERM_ACCESS_REPORTS => true];
        $this->agent->save();
        Livewire::withoutLazyLoading();
        $this->actingAs($this->agent->fresh())->get(route('reports.conversations', ['period' => 'last_7', 'mailbox' => $this->sales->id]))->assertOk()
            ->assertDontSee('Sales');
        $data = (new ConversationsReport($this->agent->fresh(), ['period' => 'last_7', 'mailbox' => $this->sales->id]))->data();
        $this->assertSame(2, $data['metrics']['new']['value'], 'Not a mailbox they can view: their own.');
        $this->assertSame([], $data['table_mailboxes']);
    }

    public function testTypeFilterSeparatesEmailFromChannels()
    {
        $yesterday = Carbon::now('UTC')->subDays(1)->setTime(10, 0);
        $this->conversation($this->support, $yesterday);
        $telegram = $this->conversation($this->support, $yesterday->copy()->addHour(), 'sam@customer.example.org');
        $telegram->channel = \App\Telegram\Telegram::CHANNEL;
        $telegram->save();

        $this->assertArrayHasKey('channel-'.\App\Telegram\Telegram::CHANNEL, Report::types());
        $this->assertArrayNotHasKey(Conversation::TYPE_CHAT, Report::types());
        $new = fn ($type) => (new ConversationsReport($this->admin, ['period' => 'last_7', 'type' => $type]))->data()['metrics']['new']['value'];
        $this->assertSame(2, $new(''));
        $this->assertSame(1, $new((string) Conversation::TYPE_EMAIL));
        $this->assertSame(1, $new('channel-'.\App\Telegram\Telegram::CHANNEL));
    }

    public function testReportFiguresLoadAfterThePage()
    {
        $this->conversation($this->sales, Carbon::now('UTC')->subDays(1)->setTime(10, 0));

        // The page shows the filters, and a placeholder for the figures.
        $this->actingAs($this->admin)->get(route('reports.conversations', ['period' => 'last_7']))->assertOk()
            ->assertSee('id="rpt_filters"', false)->assertSee('lazy-placeholder', false)
            ->assertDontSee('Most Active Customers');

        // The figures, with links that keep the page's filters.
        Livewire::withoutLazyLoading();
        Livewire::actingAs($this->admin)->test(ReportResults::class, ['name' => 'conversations', 'query' => ['period' => 'last_7']])
            ->assertSee('Most Active Customers')
            ->assertSeeHtml(e(route('reports.conversations', ['period' => 'last_7', 'mailbox' => $this->sales->id])));

        Livewire::withoutLazyLoading();
        Livewire::actingAs($this->agent)->test(ReportResults::class, ['name' => 'conversations'])->assertForbidden();
    }

    public function testProductivityReport()
    {
        $colleague = $this->createUser(['first_name' => 'Casey', 'last_name' => 'Colleague']);
        $this->support->users()->attach($colleague->id);
        $day = Carbon::now('UTC')->subDays(3)->setTime(9, 0);
        $quick = $this->conversation($this->support, $day);
        $this->thread($quick, $day->copy()->addMinutes(10), $this->agent);
        $this->close($quick, $day->copy()->addMinutes(10), $this->agent);
        $slow = $this->conversation($this->support, $day->copy()->addHour(), 'sam@customer.example.org');
        $this->thread($slow, $day->copy()->addHours(4), $colleague);
        $this->thread($slow, $day->copy()->addHours(5));
        $this->thread($slow, $day->copy()->addHours(6), $this->agent);
        $this->close($slow, $day->copy()->addDays(2), $colleague);
        Replies::update([$quick->id, $slow->id]);

        $data = (new ProductivityReport($this->admin, ['period' => 'last_7']))->data();
        $this->assertSame(3, $data['metrics']['replies']['value']);
        $this->assertSame(2, $data['metrics']['customers_helped']['value']);
        $this->assertSame(2, $data['metrics']['closed']['value']);
        $this->assertSame(1, $data['metrics']['rfr']['value']);
        $this->assertSame(2, $data['table_first_response_time']['count']);
        $this->assertSame((600 + 3 * 3600) / 2, $data['table_first_response_time']['median']);
        $this->assertSame(3, $data['table_response_time']['count']);
        $this->assertSame(['< 15 min', '1-2 days'], array_column($data['table_resolution_time']['rows'], 'title'));
        $this->assertSame(1.5, $data['table_replies_to_resolve']['average']);
        $this->assertSame(['Robin Reply', 'Casey Colleague'], array_column($data['table_users'], 'name'));
        $this->assertSame([2, 1], array_column($data['table_users'], 'replies'));
        $this->assertSame([600, 10800], array_column($data['table_users'], 'first_response_time'));

        // One user.
        $data = (new ProductivityReport($this->admin, ['period' => 'last_7', 'user' => $colleague->id]))->data();
        $this->assertSame(1, $data['metrics']['replies']['value']);
        $this->assertSame(1, $data['metrics']['closed']['value']);
        $this->assertSame(0, $data['metrics']['rfr']['value']);

        Livewire::withoutLazyLoading();
        $page = $this->actingAs($this->admin)->get(route('reports.productivity', ['period' => 'last_7', 'chart' => 'closed', 'group_by' => 'd']))->assertOk()
            ->assertSee('First Response Time')->assertSee('Robin Reply');
        $this->assertMatchesRegularExpression('#<option value="closed"\s+selected#', $page->getContent());
    }

    public function testPeriodsAndChart()
    {
        $today = CarbonImmutable::create(2026, 3, 18, 0, 0, 0, 'UTC');
        $this->assertSame(['2026-02-01', '2026-02-28'], array_map(fn ($day) => $day->format('Y-m-d'), Report::periodDates('last_month', $today)));
        $this->assertSame(['2026-03-12', '2026-03-18'], array_map(fn ($day) => $day->format('Y-m-d'), Report::periodDates('last_7', $today)));

        // In the viewer's timezone; the previous period just before, as long.
        $viewer = $this->createAdmin(['timezone' => 'Europe/Amsterdam']);
        $report = new ConversationsReport($viewer, ['period' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-28']);
        $this->assertSame(['2025-12-31 23:00:00', '2026-01-28 22:59:59'], $report->range());
        $this->assertSame(['2025-12-03 23:00:00', '2025-12-31 22:59:59'], $report->range(true));
        $this->assertSame(['d', 'w'], $report->groupBys());

        // Weeks add up their days.
        $chart = $report->chart(['group_by' => 'w'], ['new_conv' => 'New'], [
            '2026-01-01 08:00:00', '2026-01-02 08:00:00', '2026-01-07 22:30:00', '2026-01-28 12:00:00',
        ], ['2025-12-04 12:00:00']);
        $this->assertSame([3, 0, 0, 1], $chart['datasets'][0]['data'], 'Jan 7 23:30 in Amsterdam: the first week.');
        $this->assertSame(4, count($chart['labels']));

        $chart = $report->chart(['group_by' => 'w'], ['new_conv' => 'New'], ['2026-01-07 23:30:00', '2026-01-28 12:00:00'], ['2025-12-04 12:00:00']);
        $this->assertSame([0, 1, 0, 1], $chart['datasets'][0]['data'], 'Jan 8 00:30 in Amsterdam: the second week.');
        $this->assertSame([1, 0, 0, 0], $chart['datasets'][1]['data']);

        $this->assertSame([4, 8, 16, 24, 100], array_map([\App\Reports\Chart::class, 'niceMax'], [3, 5, 14, 23, 99]), 'Four whole steps.');
        $this->assertSame(null, Report::change(5, 0));
        $this->assertSame(-50, Report::change(5, 10));
        $this->assertSame('1 d 2 h', Report::duration(93600));
        $this->assertSame('2 h 5 min', Report::duration(7500));
    }

    public function testApi()
    {
        $this->conversation($this->support, Carbon::now('UTC')->subDay());

        $response = $this->json('GET', '/api/reports/conversations', [], ['X-FreeScout-API-Key' => ApiKey::globalKey()])->assertOk();
        $response->assertJsonPath('report', 'conversations')->assertJsonPath('metrics.new.value', 1)
            ->assertJsonPath('filters.to', date('Y-m-d'))->assertJsonStructure(['chart' => ['labels', 'datasets'], 'table_customers', 'table_mailboxes']);
        $this->json('GET', '/api/reports/productivity?filters[from]=2020-01-01&filters[to]=2020-01-31', [], ['X-FreeScout-API-Key' => ApiKey::globalKey()])
            ->assertOk()->assertJsonPath('metrics.replies.value', 0)->assertJsonPath('filters.from', '2020-01-01');
        $this->json('GET', '/api/reports/satisfaction', [], ['X-FreeScout-API-Key' => ApiKey::globalKey()])->assertStatus(400);
    }
}
