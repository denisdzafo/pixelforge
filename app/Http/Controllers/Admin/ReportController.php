<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminReportRequest;
use App\Services\Reports\AdminReportService;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __construct(private readonly AdminReportService $reportService) {}

    public function __invoke(AdminReportRequest $request): Response
    {
        [$start, $end] = $request->range();

        return Inertia::render('admin/Report', [
            'report' => $this->reportService->build($start, $end),
            'maxDays' => AdminReportRequest::MAX_DAYS,
        ]);
    }
}
