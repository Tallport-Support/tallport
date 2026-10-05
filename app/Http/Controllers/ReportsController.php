<?php

namespace App\Http\Controllers;

use App\Reports\ConversationsReport;
use App\Reports\ProductivityReport;
use App\User;
use Illuminate\Http\Request;

/**
 * Reports: for administrators and users allowed to see them, on the
 * mailboxes they can view.
 */
class ReportsController extends Controller
{
    const REPORTS = [
        'conversations' => ConversationsReport::class,
        'productivity'  => ProductivityReport::class,
    ];

    public function __construct()
    {
        $this->middleware('auth');
    }

    public static function canAccess(?User $user)
    {
        return $user && ($user->isAdmin() || $user->hasPermission(User::PERM_ACCESS_REPORTS));
    }

    public function conversations(Request $request)
    {
        return $this->show($request, 'conversations');
    }

    public function productivity(Request $request)
    {
        return $this->show($request, 'productivity');
    }

    protected function show(Request $request, $name)
    {
        if (!self::canAccess(auth()->user())) {
            abort(403);
        }
        $class = self::REPORTS[$name];
        $report = new $class(auth()->user(), (array) $request->query());

        // The figures load after the page (App\Livewire\ReportResults).
        return view('reports/'.$name, [
            'name'   => $name,
            'report' => $report,
        ]);
    }
}
