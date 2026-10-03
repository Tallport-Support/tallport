<?php

namespace App\Http\Controllers\Api;

use App\User;
use Illuminate\Http\Request;

/**
 * GET /api/reports/{conversations|productivity}: a report's numbers,
 * chart and tables (App\Reports).
 */
class ReportsController extends ApiController
{
    public function show(Request $request, $name)
    {
        if (!$this->access()->isAdmin()) {
            return $this->forbiddenAdmin();
        }
        $reports = \App\Http\Controllers\ReportsController::REPORTS;
        if (!isset($reports[$name])) {
            return $this->error('Unknown report `'.$name.'`. Allowed values: '.implode(', ', array_keys($reports)), 'reportName');
        }
        $viewer = auth()->user();
        if ($this->access()->isGlobal() || !$viewer) {
            // Reports are for a user: the first administrator.
            $viewer = User::nonDeleted()->where('role', User::ROLE_ADMIN)->orderBy('id')->first();
        }
        if (!$viewer) {
            return $this->forbiddenAdmin();
        }

        $filters = (array) $request->input('filters', []);
        $to = $filters['to'] ?? date('Y-m-d');
        $input = [
            'period'  => 'custom',
            'from'    => $filters['from'] ?? date('Y-m-d', strtotime($to.' -1 week')),
            'to'      => $to,
            'mailbox' => $filters['mailbox'] ?? null,
            'type'    => $filters['type'] ?? null,
            'user'    => $filters['user'] ?? null,
        ];
        $report = new $reports[$name]($viewer, $input);
        $data = $report->data((array) $request->input('chart', []));

        return response()->json(array_merge(['report' => $name, 'filters' => $report->filters], $data));
    }
}
