<?php

namespace App;

use App\Thread;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\RedisQueue;

class Job extends Model
{
	const UPDATED_AT = null;

	public $payload_decoded = null;

    /**
     * Key of the Redis list or sorted set holding a job of a Redis queue.
     */
    public $redis_key = null;

    /**
     * Automatically converted into Carbon dates.
     */
    protected $dates = ['created_at', 'available_at', 'reserved_at'];

    /**
     * Jobs waiting in the default queue connection, newest first: rows of
     * the jobs table, or the jobs of a Redis queue (with the payload's uuid
     * as their id).
     */
    public static function pending($queue = null, $display_name = null, $contains = null, $limit = null)
    {
        $connection = \Queue::connection();
        if ($connection instanceof RedisQueue) {
            $jobs = self::pendingInRedis($connection, $queue)->filter(function ($job) use ($display_name, $contains) {
                return (!$display_name || ($job->getPayloadDecoded()['displayName'] ?? '') == $display_name)
                    && (!$contains || str_contains($job->payload, $contains));
            })->sortByDesc('created_at')->values();

            return $limit ? $jobs->take($limit) : $jobs;
        }

        $query = self::orderBy('created_at', 'desc')->orderBy('id', 'desc');
        if ($queue) {
            $query->where('queue', $queue);
        }
        if ($display_name) {
            $query->where('payload', 'like', '%"displayName":"'.str_replace('\\', '\\\\\\\\', $display_name).'"%');
        }
        if ($contains) {
            $query->where('payload', 'like', '%'.addcslashes($contains, '%_\\').'%');
        }
        if ($limit) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * A waiting job by its id (see pending()).
     */
    public static function findPending($id)
    {
        if (\Queue::connection() instanceof RedisQueue) {
            return self::pending()->first(function ($job) use ($id) {
                return $job->id === (string) $id;
            });
        }

        return self::find($id);
    }

    /**
     * Jobs of a Redis queue: waiting, delayed and running.
     */
    protected static function pendingInRedis(RedisQueue $connection, $queue = null)
    {
        $redis = $connection->getConnection();
        if ($queue) {
            $queues = [$queue];
        } else {
            $queues = collect($redis->keys('queues:*'))->map(function ($key) {
                return \Str::between($key, 'queues:', ':');
            })->unique()->all();
        }
        $retry_after = (int) config('queue.connections.'.$connection->getConnectionName().'.retry_after', 90);

        $jobs = collect();
        foreach ($queues as $name) {
            $key = $connection->getQueue($name);
            $members = [$key => array_fill_keys($redis->lrange($key, 0, -1), null)];
            foreach ([':delayed', ':reserved'] as $set) {
                $members[$key.$set] = $redis->zrange($key.$set, 0, -1, ['withscores' => true]) ?: [];
            }
            foreach ($members as $member_key => $payloads) {
                foreach ($payloads as $payload => $score) {
                    $decoded = json_decode($payload, true) ?: [];
                    $job = new self();
                    $job->setIncrementing(false)->setKeyType('string')->forceFill([
                        'id'           => (string) ($decoded['uuid'] ?? $decoded['id'] ?? md5($payload)),
                        'queue'        => $name,
                        'payload'      => $payload,
                        'attempts'     => (int) ($decoded['attempts'] ?? 0),
                        'reserved_at'  => str_ends_with($member_key, ':reserved') ? (int) $score - $retry_after : null,
                        'available_at' => str_ends_with($member_key, ':delayed') ? (int) $score : ($decoded['createdAt'] ?? time()),
                        'created_at'   => $decoded['createdAt'] ?? time(),
                    ]);
                    $job->redis_key = $member_key;
                    $jobs->push($job);
                }
            }
        }

        return $jobs;
    }

    /**
     * Remove the job from the queue.
     */
    public function cancel()
    {
        if (!$this->redis_key) {
            return $this->delete();
        }
        $redis = \Queue::connection()->getConnection();
        if (str_ends_with($this->redis_key, ':delayed') || str_ends_with($this->redis_key, ':reserved')) {
            return (bool) $redis->zrem($this->redis_key, $this->payload);
        }

        return (bool) $redis->lrem($this->redis_key, 0, $this->payload);
    }

    /**
     * Run a delayed job now.
     */
    public function runNow()
    {
        if (!$this->redis_key) {
            $this->available_at = time();
            return $this->save();
        }
        $connection = \Queue::connection();
        if (str_ends_with($this->redis_key, ':delayed') && $connection->getConnection()->zrem($this->redis_key, $this->payload)) {
            $connection->pushRaw($this->payload, $this->queue);
        }

        return true;
    }

    /**
     * Redis keys had no prefix before Tallport 1.26.0: move a Redis queue's
     * waiting and delayed jobs from those keys to the prefixed ones (running
     * jobs are left to their worker). Returns the number of keys moved.
     */
    public static function moveUnprefixedRedisJobs()
    {
        $connection = \Queue::connection();
        if (!$connection instanceof RedisQueue) {
            return 0;
        }
        $client = $connection->getConnection()->client();
        $prefix = $client instanceof \Redis ? (string) $client->getOption(\Redis::OPT_PREFIX) : '';
        if ($prefix === '') {
            return 0;
        }

        $moved = 0;
        $client->setOption(\Redis::OPT_PREFIX, '');
        try {
            foreach ($client->keys('queues:*') as $key) {
                $type = $client->type($key);
                if (str_ends_with($key, ':reserved') || !in_array($type, [\Redis::REDIS_LIST, \Redis::REDIS_ZSET])) {
                    continue;
                }
                if (!$client->exists($prefix.$key)) {
                    $client->rename($key, $prefix.$key);
                } elseif ($type == \Redis::REDIS_LIST) {
                    // Ahead of the jobs queued since, in their order.
                    do {
                        $payload = $client->rPopLPush($key, $prefix.$key);
                    } while ($payload !== false);
                } else {
                    foreach ($client->zRange($key, 0, -1, ['withscores' => true]) as $payload => $score) {
                        $client->zAdd($prefix.$key, $score, $payload);
                    }
                    $client->del($key);
                }
                $moved++;
            }
        } finally {
            $client->setOption(\Redis::OPT_PREFIX, $prefix);
        }

        return $moved;
    }

    public function getPayloadDecoded()
    {
    	if ($this->payload_decoded !== null) {
    		return $this->payload_decoded;
    	}

    	$this->payload_decoded = json_decode($this->payload, true);

    	return $this->payload_decoded;
    }

    public function getCommand($allowed_classes = [])
    {
    	return self::getPayloadCommand($this->getPayloadDecoded(), $allowed_classes);
    }

    public function getCommandLastThread()
    {
	    $command = $this->getCommand();
        if ($command && !empty($command->threads)) {
            return Thread::getLastThread($command->threads);
        }

        return null;
    }

    public static function getPayloadCommand($payload, $allowed_classes = [])
    {
    	if (empty($payload['data']) || empty($payload['data']['command'])) {
    		return null;
    	}
        if (!$allowed_classes) {
            $allowed_classes = [
                'App\Jobs\SendReplyToCustomer',
                'App\Jobs\SendReplyToTelegram',
                'App\Jobs\SendReplyToNostr',
                'App\Jobs\SendNotificationToUsers',
                'App\Jobs\SendAutoReply',
                'App\Jobs\SendAlert',
                'App\Jobs\SendEmailReplyError',
                'Illuminate\Contracts\Database\ModelIdentifier',
            ];
        }
        try {
            // If some record has been deleted from DB, there will be an error:
            // No query results for model [App\Conversation].
            return unserialize($payload['data']['command'], ['allowed_classes' => $allowed_classes]);
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function getTriggerActionName($payload)
    {
        return preg_replace('/^.*?action";s:\d+:"([^"]+)".*$/s', '$1', $payload['data']['command'] ?? '');
    }
}
