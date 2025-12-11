<?php

namespace App\Http\Controllers\ClerkDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;

class ClerkController extends Controller
{
    // Get logged-in clerk info
    public function me(Request $request)
    {
        // assuming JWT auth
        $clerk = auth()->user();

        if (!$clerk || $clerk->role !== 'clerk') {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json($clerk);
    }

    // Get all customers for clerk sidebar (limit 5)
    public function customers(Request $request)
    {
        $limit = $request->query('limit', 5);
        $limit = min((int) $limit, 100);
        
        $customers = User::where('role', 'customer')
            ->limit($limit)
            ->get();
        return response()->json($customers);
    }
}
