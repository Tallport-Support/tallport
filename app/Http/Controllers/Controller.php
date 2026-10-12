<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * A settings form saved as it changes (Tallport.autoSave()) is answered in JSON: what's wrong
     * (said on each field's row) or done; the form posted the usual way gets its redirect.
     */
    protected function savedResponse($redirect, $validator = null)
    {
        if (!request()->ajax()) {
            return $redirect;
        }
        if ($validator) {
            return response()->json(['status' => 'error', 'msg' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        return response()->json(['status' => 'success']);
    }
}
