<?php

namespace App\Jobs;

use App\Customer;
use App\Misc\CustomerPhotos;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A customer's photo, looked up online (Gravatar, Unavatar…).
 */
class FetchCustomerPhoto implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $customer_id;

    public $email;

    public $tries = 1;

    public function __construct($customer_id, $email)
    {
        $this->customer_id = $customer_id;
        $this->email = $email;
    }

    public function handle()
    {
        $customer = Customer::find($this->customer_id);
        if ($customer && CustomerPhotos::isEnabled()) {
            CustomerPhotos::fetch($customer, $this->email);
        }
    }
}
