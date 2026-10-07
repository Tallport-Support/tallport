<?php

namespace Tests\Feature;

use App\Job;
use Tests\FeatureTestCase;

/**
 * Waiting jobs of the database queue as System » Status lists and handles
 * them (Redis queues: RedisTest), and reading the command a job carries.
 */
class JobModelTest extends FeatureTestCase
{
    protected function queueJob($display_name, array $data = [], $available_at = null, $queue = 'emails')
    {
        $id = \DB::table('jobs')->insertGetId([
            'queue'        => $queue,
            'payload'      => json_encode(array_merge(['displayName' => $display_name], $data)),
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => $available_at ?: time() + 3600,
            'created_at'   => time(),
        ]);

        return Job::find($id);
    }

    public function testPendingJobsAreFiltered()
    {
        $reply = $this->queueJob('App\Jobs\SendReplyToCustomer', ['note' => 'thread 100%_done!']);
        $notification = $this->queueJob('App\Jobs\SendNotificationToUsers', ['note' => 'thread 100 done']);

        $this->assertSame([$reply->id], Job::pending('emails', 'App\Jobs\SendReplyToCustomer')->pluck('id')->all());
        // LIKE wildcards in the text are matched literally.
        $this->assertSame([$reply->id], Job::pending('emails', null, '100%_done!')->pluck('id')->all());
        $this->assertCount(1, Job::pending('emails', null, null, 1));
        $this->assertSame([], Job::pending('emails', 'App\Jobs\SendAlert')->all());
        $this->assertSame($notification->id, Job::findPending($notification->id)->id);
    }

    public function testCancelAndRunNowFromTheStatusPage()
    {
        $admin = $this->createAdmin();
        $cancelled = $this->queueJob('App\Jobs\SendReplyToCustomer');
        $delayed = $this->queueJob('App\Jobs\SendReplyToCustomer');

        $this->actingAs($admin)->post(route('system.action'), ['action' => 'cancel_job', 'job_id' => $cancelled->id]);
        $this->assertNull(Job::find($cancelled->id));

        $this->actingAs($admin)->post(route('system.action'), ['action' => 'retry_job', 'job_id' => $delayed->id]);
        $this->assertLessThanOrEqual(time(), Job::find($delayed->id)->available_at->getTimestamp());
    }

    public function testTheCommandOfAJob()
    {
        $command = new \App\Jobs\SendAlert('Alert text');

        $this->assertNull(Job::getPayloadCommand([]));
        $this->assertNull(Job::getPayloadCommand(['data' => ['command' => 'not serialized']]));
        $this->assertInstanceOf(\App\Jobs\SendAlert::class, Job::getPayloadCommand(['data' => ['command' => serialize($command)]]));
        // Classes that aren't allowed come back incomplete.
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, Job::getPayloadCommand(['data' => ['command' => serialize($command)]], ['App\Jobs\SendAutoReply']));

        $job = $this->queueJob('App\Jobs\SendAlert', ['data' => ['command' => serialize($command)]]);
        $this->assertNull($job->getCommandLastThread());
    }

    public function testMovingUnprefixedRedisJobsNeedsARedisQueue()
    {
        $this->assertSame(0, Job::moveUnprefixedRedisJobs());
    }

    /**
     * Delayed jobs under both keys end up together; running jobs stay with their worker.
     */
    public function testUnprefixedDelayedRedisJobsJoinThePrefixedOnes()
    {
        try {
            app('redis')->connection()->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('No Redis server: '.$e->getMessage());
        }
        config(['queue.default' => 'redis']);
        $redis = \Queue::connection('redis');
        $redis->clear('emails');
        $client = $redis->getConnection()->client();
        $prefix = $client->getOption(\Redis::OPT_PREFIX);

        try {
            $client->zAdd('queues:emails:delayed', time() + 60, json_encode(['uuid' => 'prefixed-delayed']));
            $client->setOption(\Redis::OPT_PREFIX, '');
            $client->zAdd('queues:emails:delayed', time() + 60, json_encode(['uuid' => 'unprefixed-delayed']));
            $client->zAdd('queues:emails:reserved', time() + 60, json_encode(['uuid' => 'running']));
            $client->setOption(\Redis::OPT_PREFIX, $prefix);

            $this->assertSame(1, Job::moveUnprefixedRedisJobs());

            $this->assertEqualsCanonicalizing(['prefixed-delayed', 'unprefixed-delayed'], Job::pending('emails')->pluck('id')->all());
            $client->setOption(\Redis::OPT_PREFIX, '');
            $this->assertSame(['queues:emails:reserved'], $client->keys('queues:*'));
        } finally {
            $client->setOption(\Redis::OPT_PREFIX, '');
            $client->del('queues:emails:reserved', 'queues:emails:delayed');
            $client->setOption(\Redis::OPT_PREFIX, $prefix);
            $redis->clear('emails');
        }
    }
}
