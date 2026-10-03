<?php

namespace App\Api;

use App\Conversation;
use App\Customer;
use App\Jobs\SendWebhook;

/**
 * Sends events to webhooks: the conversation or customer in the API's JSON,
 * as it is when the event happens, delivered by a queued job.
 */
class Webhooks
{
    /**
     * Events, by the action they follow.
     */
    public static function listen()
    {
        $conversation_event = function ($event) {
            return function ($conversation) use ($event) {
                self::trigger($event, $conversation);
            };
        };
        \Eventy::addAction('conversation.created_by_user', $conversation_event('convo.created'), 20, 1);
        \Eventy::addAction('conversation.created_by_customer', $conversation_event('convo.created'), 20, 1);
        \Eventy::addAction('conversation.deleted', $conversation_event('convo.deleted'), 20, 1);
        \Eventy::addAction('conversation.moved', $conversation_event('convo.moved'), 20, 1);
        \Eventy::addAction('conversation.status_changed', $conversation_event('convo.status'), 20, 1);
        \Eventy::addAction('conversation.note_added', $conversation_event('convo.note.created'), 20, 1);
        \Eventy::addAction('conversation.user_changed', function ($conversation) {
            if ($conversation->user_id) {
                self::trigger('convo.assigned', $conversation);
            }
        }, 20, 1);
        \Eventy::addAction('conversation.state_changed', function ($conversation, $user, $prev_state) {
            if ($prev_state == Conversation::STATE_DELETED && $conversation->state == Conversation::STATE_PUBLISHED) {
                self::trigger('convo.restored', $conversation);
            }
        }, 20, 3);
        foreach (['conversation.customer_replied' => 'convo.customer.reply.created', 'conversation.user_replied' => 'convo.agent.reply.created'] as $action => $event) {
            \Eventy::addAction($action, function ($conversation, $thread) use ($event) {
                // The preview shows the reply.
                $conversation->setPreview($thread->body);
                self::trigger($event, $conversation);
            }, 20, 2);
        }
        \Eventy::addAction('conversation.deleting', $conversation_event('convo.deleted_forever'), 20, 1);
        \Eventy::addAction('conversations.before_delete_forever', function ($conversation_ids) {
            if (self::webhooksFor('convo.deleted_forever')->isNotEmpty()) {
                foreach (Conversation::whereIn('id', (array) $conversation_ids)->get() as $conversation) {
                    self::trigger('convo.deleted_forever', $conversation);
                }
            }
        }, 20, 1);
        \Eventy::addAction('customer.created', function ($customer) {
            self::trigger('customer.created', $customer);
        }, 20, 1);
        \Eventy::addAction('customer.updated', function ($customer) {
            self::trigger('customer.updated', $customer);
        }, 20, 1);

        self::listenToWorkflows();
    }

    /**
     * Webhooks that want an event.
     */
    protected static function webhooksFor($event)
    {
        try {
            return Webhook::all()->filter(function ($webhook) use ($event) {
                return in_array($event, (array) $webhook->events);
            });
        } catch (\Throwable $e) {
            // Before the migration.
            return collect();
        }
    }

    /**
     * Send an event about a conversation or customer to the webhooks that want it.
     */
    public static function trigger($event, $entity)
    {
        $webhooks = self::webhooksFor($event)->filter(function ($webhook) use ($event, $entity) {
            return $webhook->wants($event, $entity);
        });
        if ($webhooks->isEmpty()) {
            return;
        }
        $payload = $entity instanceof Customer ? Format::customer($entity) : Format::conversation($entity);
        $payload = \Eventy::filter('apiwebhooks.webhook.before_run', $payload, $event, $entity);
        $body = json_encode($payload);

        foreach ($webhooks as $webhook) {
            SendWebhook::dispatch($webhook->id, $event, $body)->onQueue('default')->afterCommit();
        }
    }

    /**
     * Workflows: a "Trigger Webhook" action sends an event of your choice
     * (e.g. custom.webhook.event), which webhooks can be for.
     */
    protected static function listenToWorkflows()
    {
        \Eventy::addFilter('workflows.actions_config', function ($config) {
            if (isset($config['dummy']['items'])) {
                $config['dummy']['items']['webhook'] = [
                    'title'       => __('Trigger Webhook'),
                    'values_type' => 'text',
                    'placeholder' => 'custom.webhook.event',
                ];
            }

            return $config;
        }, 20, 1);

        \Eventy::addFilter('workflow.perform_action', function ($performed, $type, $operator, $value, $conversation) {
            if ($type == 'webhook' && is_string($value) && trim($value) !== '') {
                self::trigger(trim($value), $conversation);

                return true;
            }

            return $performed;
        }, 20, 5);

        // Events workflows trigger can be chosen for webhooks.
        \Eventy::addFilter('webhooks.events', function ($events) {
            if (!\Schema::hasTable('workflows')) {
                return $events;
            }
            foreach (\DB::table('workflows')->where('actions', 'like', '%"type":"webhook"%')->pluck('actions') as $actions) {
                foreach (self::workflowWebhookEvents(json_decode($actions, true)) as $event) {
                    $events[] = $event;
                }
            }

            return $events;
        }, 20, 1);
    }

    /**
     * The values of "webhook" actions in a workflow's actions.
     */
    protected static function workflowWebhookEvents($actions)
    {
        $events = [];
        if (!is_array($actions)) {
            return $events;
        }
        if (($actions['type'] ?? null) === 'webhook' && is_string($actions['value'] ?? null) && trim($actions['value']) !== '') {
            $events[] = trim($actions['value']);
        }
        foreach ($actions as $item) {
            if (is_array($item)) {
                $events = array_merge($events, self::workflowWebhookEvents($item));
            }
        }

        return $events;
    }
}
