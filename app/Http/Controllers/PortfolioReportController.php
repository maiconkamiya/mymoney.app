<?php

namespace App\Http\Controllers;

use App\Models\Portfolio;
use App\Reports\TaxReport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PortfolioReportController extends Controller
{
    /**
     * Operations and realised result per trade as a CSV for the income-tax return.
     */
    public function tax(Request $request, Portfolio $portfolio, TaxReport $report): StreamedResponse
    {
        abort_unless($portfolio->user_id === $request->user()->id, 403);

        $year = $request->integer('year') ?: null;
        $operations = $report->operations($portfolio, $year);

        return response()->streamDownload(function () use ($operations) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fputcsv($out, ['data', 'mes', 'ativo', 'lado', 'quantidade', 'preco', 'valor', 'taxas', 'resultado'], escape: '');

            foreach ($operations as $row) {
                fputcsv($out, array_values($row), escape: '');
            }

            fclose($out);
        }, sprintf('ir-%s%s.csv', str($portfolio->name)->slug(), $year ? "-{$year}" : ''), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
