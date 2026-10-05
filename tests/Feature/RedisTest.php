<?php

namespace Tests\Feature;

use App\Conversation;
use App\Job;
use App\Thread;
use Tests\FeatureTestCase;

/**
 * Laravel's Redis support: the queue, cache and sessions on Redis, and
 * Tallport's own use of queued jobs (Undo, Retry, monitors, System » Status)
 * with a Redis queue. Skipped without a Redis server.
 */
class RedisTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;
    protected $redis_available = false;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            app('redis')->connection()->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('No Redis server: '.$e->getMessage());
        }
        $this->redis_available = true;
        config(['queue.default' => 'redis']);
        $this->clearRedisQueues();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
    }

    protected function tearDown(): void
    {
        if ($this->redis_available) {
            $this->clearRedisQueues();
        }

        parent::tearDown();
    }

    protected function clearRedisQueues()
    {
        $redis = \Queue::connection('redis');
        foreach (['emails', 'default'] as $queue) {
            $redis->clear($queue);
        }
    }

    protected function queuedReply()
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'Casey Customer <casey@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => 'Question',
        ]));
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
        $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => '<p>Our answer</p>',
        ]);

        return [$conversation, $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first()];
    }

    public function testRepliesAreQueuedInRedisAndSent()
    {
        [, $reply] = $this->queuedReply();

        $this->assertSame(0, \DB::table('jobs')->where('queue', 'emails')->count());
        $job_id = $reply->getQueuedJobId();
        $this->assertNotNull($job_id, 'The reply waits in the Redis queue (for the undo delay).');
        $this->assertGreaterThan(0, \Queue::connection('redis')->delayedSize('emails'));

        Job::findPending($job_id)->runNow();
        $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'emails', '--stop-when-empty' => true]);

        $this->assertCount(1, $this->sentEmailsTo('casey@customer.example.org'));
        $this->assertNull($reply->getQueuedJobId());
    }

    public function testUndoCancelsTheReplyInRedis()
    {
        [, $reply] = $this->queuedReply();
        \Session::start();

        $this->actingAs($this->agent)->get('/conversation/undo-reply/'.$reply->id.'/'.csrf_token());

        $this->assertSame(Thread::STATE_DRAFT, (int) $reply->fresh()->state);
        $this->assertNull($reply->getQueuedJobId());
        $this->assertCount(0, Job::pending('emails', 'App\Jobs\SendReplyToCustomer'));
    }

    public function testStatusPageListsAndCancelsRedisJobs()
    {
        [$conversation, $reply] = $this->queuedReply();
        $admin = $this->createAdmin();

        $this->actingAs($admin)->get(route('system'))
            ->assertOk()
            ->assertSee('Send reply to customer')
            ->assertSee('#'.$conversation->number)
            ->assertSeeInOrder(['Redis', 'queue']);

        $this->actingAs($admin)->post(route('system.action'), ['action' => 'cancel_job', 'job_id' => $reply->getQueuedJobId()]);

        $this->assertNull($reply->getQueuedJobId());
    }

    public function testSendMonitorSeesStuckRedisJobs()
    {
        \Queue::connection('redis')->pushRaw(json_encode([
            'uuid' => '6f1c0d2e-0000-4000-8000-000000000002', 'displayName' => 'App\Jobs\SendReplyToCustomer',
            'attempts' => 0, 'createdAt' => time() - 13 * 3600,
        ]), 'emails');

        $this->artisan('tallport:send-monitor');
        $this->assertSame('1', \Option::get('send_emails_problem'));

        Job::findPending('6f1c0d2e-0000-4000-8000-000000000002')->cancel();
        $this->artisan('tallport:send-monitor');
        $this->assertSame('', (string) \Option::get('send_emails_problem'));
    }

    public function testClearingTheCacheKeepsQueuedJobs()
    {
        config(['cache.default' => 'redis']);
        \Cache::put('redis_test', 'cached', 60);
        $this->assertSame('cached', \Cache::get('redis_test'));
        \Queue::connection('redis')->pushRaw(json_encode(['uuid' => 'kept', 'displayName' => 'App\Jobs\SendReplyToCustomer']), 'emails');

        $this->artisan('cache:clear');

        $this->assertNull(\Cache::get('redis_test'));
        $this->assertNotNull(Job::findPending('kept'), 'The cache has a Redis database of its own.');
    }

    public function testSessionsInRedis()
    {
        config(['session.driver' => 'redis']);
        $session = app('session')->driver('redis');
        $session->start();
        $session->put('redis_test', 'kept');
        $session->save();

        $read = app('session')->driver('redis');
        $read->setId($session->getId());
        $read->start();
        $this->assertSame('kept', $read->get('redis_test'));
        $read->invalidate();
    }

    public function testUnprefixedJobsAreMovedOnUpdate()
    {
        $client = \Queue::connection('redis')->getConnection()->client();
        $prefix = $client->getOption(\Redis::OPT_PREFIX);
        $client->setOption(\Redis::OPT_PREFIX, '');
        $client->rPush('queues:emails', json_encode(['uuid' => 'unprefixed', 'displayName' => 'App\Jobs\SendReplyToCustomer']));
        $client->zAdd('queues:emails:delayed', time() + 60, json_encode(['uuid' => 'unprefixed-delayed', 'displayName' => 'App\Jobs\SendReplyToCustomer']));
        $client->setOption(\Redis::OPT_PREFIX, $prefix);
        \Queue::connection('redis')->pushRaw(json_encode(['uuid' => 'prefixed', 'displayName' => 'App\Jobs\SendReplyToCustomer']), 'emails');

        $this->assertSame(2, Job::moveUnprefixedRedisJobs());

        $this->assertEqualsCanonicalizing(['prefixed', 'unprefixed', 'unprefixed-delayed'], Job::pending('emails')->pluck('id')->all());
        $this->assertSame(['unprefixed', 'prefixed'], \Queue::connection('redis')->pendingJobs('emails')->pluck('uuid')->all(), 'Older jobs run first.');
        $client->setOption(\Redis::OPT_PREFIX, '');
        $this->assertSame([], $client->keys('queues:*'));
        $client->setOption(\Redis::OPT_PREFIX, $prefix);
    }
}
