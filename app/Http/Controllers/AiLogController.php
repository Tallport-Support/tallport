<?php

namespace App\Http\Controllers;

use App\Ai\Usage;
use Illuminate\Http\Request;

/**
 * Manage » Logs » AI: every call to an AI model (App\Ai\Usage), newest first: which provider
 * and model, the backup or not, how it went and how long it took, its tokens, and the error.
 * Filtered by feature, outcome (errors) and model.
 */
class AiLogController extends Controller
{
    const PER_PAGE = 50;

    public function index(Request $request)
    {
        $query = Usage::with(['mailbox', 'conversation'])->orderBy('created_at', 'desc')->orderBy('id', 'desc');
        $feature = (string) $request->input('feature');
        if ($feature !== '') {
            $query->where('feature', $feature);
        }
        $outcome = (string) $request->input('outcome');
        if ($outcome == 'errors') {
            $query->where('status', '!=', Usage::STATUS_OK);
        }
        $model = (string) $request->input('model');
        if ($model !== '') {
            $query->where('model', $model);
        }

        return view('secure/ai_log', [
            'calls'    => $query->paginate(self::PER_PAGE)->withQueryString(),
            'features' => Usage::select('feature')->distinct()->orderBy('feature')->pluck('feature'),
            'models'   => Usage::whereNotNull('model')->select('model')->distinct()->orderBy('model')->pluck('model'),
            'feature'  => $feature,
            'outcome'  => $outcome,
            'model'    => $model,
        ]);
    }
}
