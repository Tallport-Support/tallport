<?php

namespace App\Http\Controllers\Api;

use App\User;
use Illuminate\Http\Request;

/**
 * GET /api/reports/{name}: data of the Reports module's reports.
 */
class ReportsController extends ApiController
{
    const REPORTS = [
        'conversations' => 'getReportDataConversations',
        'productivity'  => 'getReportDataProductivity',
        'satisfaction'  => 'getReportSatisfaction',
        'time'          => 'getReportTime',
    ];

    public function show(Request $request, $name)
    {
        $controller = '\Modules\Reports\Http\Controllers\ReportsController';
        if (!\App\Module::isActive('reports') || !class_exists($controller)) {
            return $this->moduleMissing($request);
        }
        if (!$this->access()->isAdmin()) {
            return $this->forbiddenAdmin();
        }
        if (!isset(self::REPORTS[$name])) {
            return $this->error('Unknown report `'.$name.'`. Allowed values: '.implode(', ', array_keys(self::REPORTS)), 'reportName');
        }
        if ($this->access()->isGlobal()) {
            // Reports are for a user: the first administrator.
            $admin = User::nonDeleted()->where('role', User::ROLE_ADMIN)->orderBy('id')->first();
            if ($admin) {
                auth()->onceUsingId($admin->id);
            }
        }

        $filters = (array) $request->input('filters', []);
        $filters['to'] = $filters['to'] ?? date('Y-m-d');
        $filters['from'] = $filters['from'] ?? date('Y-m-d', strtotime($filters['to'].' -1 week'));
        $report_request = new Request(['filters' => $filters, 'chart' => (array) $request->input('chart', [])]);

        $method = self::REPORTS[$name];
        $data = app($controller)->$method($report_request);

        return response()->json(array_merge(['report' => $name, 'filters' => $filters], json_decode(json_encode($data), true) ?: []));
    }
}
