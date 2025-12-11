<?php

namespace App\Http\Controllers\ClerkDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class ClerkAuthController extends Controller
{
    // GET CURRENT LOGGED-IN CLERK INFO
    public function me(Request $request)
    {
        $clerk = auth('api')->user();

        if (!$clerk || $clerk->role !== 'clerk') {
            return response()->json([
                'message' => 'Unauthorized or invalid clerk account'
            ], 401);
        }

        return response()->json($clerk);
    }
}
