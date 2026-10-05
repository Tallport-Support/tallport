<?php

namespace App\Livewire;

use App\Http\Controllers\ReportsController;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A report's figures, chart and tables, loaded after the page: the filters show
 * at once, the figures when they are counted.
 */
#[Lazy]
class ReportResults extends Component
{
    /**
     * The report (ReportsController::REPORTS).
     */
    #[Locked]
    public $name;

    /**
     * The page's query: filters, chart and grouping.
     */
    #[Locked]
    public $query = [];

    public function render()
    {
        abort_unless(ReportsController::canAccess(auth()->user()) && isset(ReportsController::REPORTS[$this->name]), 403);

        $class = ReportsController::REPORTS[$this->name];
        $report = new $class(auth()->user(), (array) $this->query);

        return view('livewire/report-results', [
            'data' => $report->data(['type' => $this->query['chart'] ?? null, 'group_by' => $this->query['group_by'] ?? null]),
        ]);
    }

    /**
     * The report's page with these query parameters changed.
     */
    public function urlWith(array $query)
    {
        return route('reports.'.$this->name, array_merge((array) $this->query, $query));
    }
}
