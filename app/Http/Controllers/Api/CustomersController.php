<?php

namespace App\Http\Controllers\Api;

use App\Api\Format;
use App\Api\Writer;
use App\Conversation;
use App\Customer;
use Illuminate\Http\Request;

class CustomersController extends ApiController
{
    /**
     * GET /api/customers
     */
    public function index(Request $request)
    {
        $query = Customer::select('customers.*');

        // A user's key: customers of conversations in its mailboxes.
        if (!$this->access()->isGlobal()) {
            $query->whereIn('customers.id', Conversation::whereIn('mailbox_id', $this->access()->mailboxIds())->select('customer_id'));
        }
        foreach (['firstName' => 'first_name', 'lastName' => 'last_name'] as $name => $column) {
            if ($value = $this->param($request, $name)) {
                $query->where('customers.'.$column, $value);
            }
        }
        if ($phone = \Helper::phoneToNumeric((string) $this->param($request, 'phone'))) {
            $query->where('customers.phones', 'like', '%'.$phone.'%');
        }
        if ($email = $this->param($request, 'email')) {
            $query->whereIn('customers.id', \App\Email::where('email', \App\Email::sanitizeEmail($email))->select('customer_id'));
        }
        if ($since = $this->param($request, 'updatedSince')) {
            $query->where('customers.updated_at', '>=', Writer::date($since));
        }
        $this->sort($request, $query, [
            'createdAt' => 'customers.id',
            'firstName' => 'customers.first_name',
            'lastName'  => 'customers.last_name',
            'updatedAt' => 'customers.updated_at',
        ], 'customers.id');

        $query = \Eventy::filter('api.customers.query', $query, $request);

        return $this->paginated($request, $query->with('emails'), 'customers', function ($customer) {
            return Format::customer($customer);
        });
    }

    /**
     * GET /api/customers/{id}
     */
    public function show(Request $request, $id)
    {
        [$customer, $error] = $this->findCustomer($request, $id);

        return $error ?: response()->json(Format::customer($customer));
    }

    /**
     * POST /api/customers: a new customer.
     */
    public function store(Request $request)
    {
        [$customer, $error] = Writer::createCustomer($request->all());
        if ($error) {
            return $this->writerError($error);
        }

        return $this->created(Format::customer($customer), $customer->id);
    }

    /**
     * PUT /api/customers/{id}
     */
    public function update(Request $request, $id)
    {
        [$customer, $error] = $this->findCustomer($request, $id);
        if ($error) {
            return $error;
        }
        [, $error] = Writer::updateCustomer($customer, $request->all());

        return $error ? $this->writerError($error) : $this->noContent();
    }

    protected function findCustomer(Request $request, $id)
    {
        $customer = \Eventy::filter('api.customer.find', Customer::find($id), $request);
        if (!$customer) {
            return [null, $this->notFound()];
        }
        if (!$this->access()->canCustomer($customer)) {
            return [null, $this->forbidden('Forbidden: API key owner is not permitted to access this customer')];
        }

        return [$customer, null];
    }
}
