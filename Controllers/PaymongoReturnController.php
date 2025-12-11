<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymongoReturnController extends Controller
{
    /**
     * Handle PayMongo return_url redirects and send users back to the SPA.
     */
    public function __invoke(Request $request)
    {
        $frontendBase = rtrim((string) env('FRONTEND_URL', 'http://localhost:8080'), '/');
        $target = "{$frontendBase}/dashboard/purchases";

        $queryParams = array_filter(
            $request->only(['payment_intent_id', 'status', 'reference', 'track_id', 'message']),
            fn ($value) => filled($value)
        );

        if (!empty($queryParams)) {
            $target .= '?' . http_build_query($queryParams);
        }

        Log::info('PayMongo redirect received', [
            'incoming_query' => $request->query(),
            'redirect_target' => $target,
        ]);

        return redirect()->away($target);
    }
}

