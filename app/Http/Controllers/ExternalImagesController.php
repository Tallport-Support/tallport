<?php

namespace App\Http\Controllers;

use App\Customer;
use App\Misc\ExternalImages;
use App\Thread;
use Illuminate\Http\Request;

/**
 * Showing images from other servers: for a message, or always for a customer.
 */
class ExternalImagesController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function ajax(Request $request)
    {
        $user = auth()->user();
        $response = ['status' => 'error', 'msg' => __('Not enough permissions')];

        switch ($request->action) {
            // This message: its body with the images.
            case 'display':
            case 'display_customer':
                $thread = Thread::find($request->thread_id);
                if (!$thread || !$thread->conversation || !$user->can('view', $thread->conversation)) {
                    break;
                }
                if ($request->action == 'display') {
                    $thread->setMeta(ExternalImages::META_KEY, 1);
                    $thread->save();
                    $response['html'] = safe_raw_html(\Eventy::filter('thread.body_output', $thread->getBodyWithFormatedLinks(), $thread, $thread->conversation, $thread->conversation->mailbox));
                } elseif ($thread->customer) {
                    $thread->customer->setMeta(ExternalImages::META_KEY, 1);
                    $thread->customer->save();
                    $response['reload'] = true;
                }
                $response['status'] = 'success';
                $response['msg'] = '';
                break;

            // The customer's images hidden again.
            case 'block_customer':
                $customer = Customer::find($request->customer_id);
                if (!$customer || !$user->can('view', $customer)) {
                    break;
                }
                $customer->setMeta(ExternalImages::META_KEY, 0);
                $customer->save();
                $response = ['status' => 'success', 'msg' => '', 'reload' => true];
                break;
        }

        return \Response::json($response);
    }
}
