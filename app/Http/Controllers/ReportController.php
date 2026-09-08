<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Statement;
use App\Services\AnalyticsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class ReportController extends Controller
{
    public function statementPdf(
        Statement $statement,
        AnalyticsService $analytics,
    ): Response {
        $statement = Statement::forCurrentUser()->findOrFail($statement->id);

        $data = $analytics->analyze($statement);

        $insights = $statement->aiInsights()->get();

        $pdf = Pdf::loadView('reports.statement', [
            'statement' => $statement,
            'totals' => $data['totals'],
            'byCategory' => $data['by_category'],
            'insights' => $insights,
        ]);

        return $pdf->download("finbalance-report-{$statement->id}.pdf");
    }
}
