<?php

namespace App\Http\Controllers;

use App\Models\Portfolio;
use App\Trading\PortfolioCharts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortfolioChartsController extends Controller
{
    public function __invoke(Request $request, Portfolio $portfolio, PortfolioCharts $charts): JsonResponse
    {
        abort_unless($portfolio->user_id === $request->user()->id, 403);

        return response()->json($charts->for($portfolio))->header('Cache-Control', 'no-store');
    }
}
