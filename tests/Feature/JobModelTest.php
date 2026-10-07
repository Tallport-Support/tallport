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
     * @dataProvider activeJobs
     */
    public function testASecondDatabaseWorkerCannotReserveAnActiveJob($queue, $connection, $maximum_execution)
    {
        $first_worker = \Queue::connection($connection);
        // Jobs queued before the split are still in the default connection's storage.
        $id = \Queue::connection('database')->pushRaw('{"uuid":"queue-reservation-test"}', $queue);

        $this->assertNotNull($first_worker->pop($queue));
        $second_worker = clone $first_worker;
        \DB::table('jobs')->where('id', $id)->update(['reserved_at' => time() - $maximum_execution]);
        $this->assertNull($second_worker->pop($queue));

        $retry_after = config('queue.connections.'.$connection.'.retry_after');
        \DB::table('jobs')->where('id', $id)->update(['reserved_at' => time() - $retry_after - 1]);
        $this->assertNotNull($second_worker->pop($queue));
    }

    public static function activeJobs()
    {
        return [
            ['default', 'database', 3600],
            ['emails', 'database_emails', 300],
            ['ai', 'database_ai', 900],
        ];
    }

    public function testTallportJobsUseTheQueueSpecificConnection()
    {
        $mail_jobs = [
            [\App\Jobs\SendAlert::class, ['alert']],
            [\App\Jobs\SendAutoReply::class, [null, null, null, null]],
            [\App\Jobs\SendEmailReplyError::class, [null, null, null]],
            [\App\Jobs\SendNotificationToUsers::class, [[], null, []]],
            [\App\Jobs\SendReplyToCustomer::class, [null, [], null]],
            [\App\Jobs\SendReplyToNostr::class, [1]],
            [\App\Jobs\SendReplyToTelegram::class, [1]],
        ];
        $ai_jobs = [
            [\App\Jobs\AiIndexDocument::class, [1]],
            [\App\Jobs\AiSummarizeConversation::class, [1, 'en']],
            [\App\Jobs\AiTranslateChat::class, [1, 'en']],
            [\App\Jobs\AiTranslateThread::class, [1, 'en']],
        ];

        foreach (['database', 'redis', 'beanstalkd', 'sync'] as $driver) {
            config(['queue.default' => $driver]);
            foreach (['emails' => $mail_jobs, 'ai' => $ai_jobs] as $queue => $jobs) {
                foreach ($jobs as [$class, $arguments]) {
                    $job = new $class(...$arguments);
                    $this->assertSame(\Helper::queueConnection($queue), $job->connection, $class.' on '.$driver);
                }
            }
        }
    }

    public function testMailQueueCapsJobsWithTheirOwnLongerTimeout()
    {
        \Queue::connection('database_emails')->push(new \App\Jobs\ApplyWorkflow(1), '', 'emails');
        $payload = json_decode(\DB::table('jobs')->where('queue', 'emails')->value('payload'), true);

        $this->assertSame(300, $payload['timeout']);
    }

    public function testOldMailJobWithLongTimeoutFailsBeforeItRuns()
    {
        \Queue::connection('database_emails')->push(new \App\Jobs\ApplyWorkflow(1), '', 'emails');
        $job = \DB::table('jobs')->where('queue', 'emails')->first();
        $payload = json_decode($job->payload, true);
        $payload['timeout'] = 1800;
        \DB::table('jobs')->where('id', $job->id)->update(['payload' => json_encode($payload)]);

        $this->artisan('queue:work', ['connection' => 'database_emails', '--queue' => 'emails', '--once' => true]);

        $this->assertNull(Job::find($job->id));
        $this->assertSame(1, \DB::table('failed_jobs')->where('queue', 'emails')->count());
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
