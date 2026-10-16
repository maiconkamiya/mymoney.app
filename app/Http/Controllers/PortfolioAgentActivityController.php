<?php

namespace App\Http\Controllers;

use App\Agents\AgentActivity;
use App\Models\Portfolio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortfolioAgentActivityController extends Controller
{
    public function __invoke(Request $request, Portfolio $portfolio, AgentActivity $activity): JsonResponse
    {
        abort_unless($portfolio->user_id === $request->user()->id, 403);

        return response()->json($activity->for($portfolio))->header('Cache-Control', 'no-store');
    }
}
