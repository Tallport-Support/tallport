<?php

namespace App\Observers;

use App\Customer;
use App\CustomerChannel;

class CustomerObserver
{
    public function updating(Customer $customer)
    {
        $phones = $customer->getPhones();
        // Set numeric phones.
        $customer->setPhones($phones);
    }

    /**
     * A new name shows in the list rows of their recent conversations (the local inbox).
     */
    public function updated(Customer $customer)
    {
        if ($customer->wasChanged(['first_name', 'last_name'])) {
            \App\Inbox\Changes::conversations($customer->conversations()->where('last_activity_at', '>', now()->subDays(\App\Inbox\Sync::RECENT_DAYS))->limit(500)->get(['id', 'mailbox_id']));
        }
    }

    public function deleting(Customer $customer)
    {
        \Eventy::action('customer.deleting', $customer);
    }

    public function created(Customer $customer)
    {
        if ($customer->channel && $customer->channel_id) {
            CustomerChannel::create($customer->id, $customer->channel, $customer->channel_id);
        }
    }

    public function deleted(Customer $customer)
    {
        CustomerChannel::where('customer_id', $customer->id)->delete();
    }
}
