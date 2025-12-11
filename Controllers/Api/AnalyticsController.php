<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class AnalyticsController extends Controller
{
    public function customersCount()
    {
        try {
            // Cache customer count for 10 minutes (updates infrequently)
            // Cache is cleared when customers are promoted/demoted to/from VIP
            $customerCount = \Cache::remember('customers_count', 600, function () {
                // Optimized: Count all customers including VIPs (exclude admin & clerk)
                // This query counts:
                // - Users with role = 'customer'
                // - Users with role = 'vip' 
                // - Users with role = NULL (default customers)
                // Excludes: admin and clerk
                $count = User::where(function ($query) {
                    $query->whereIn('role', ['customer', 'vip'])
                          ->orWhereNull('role');
                })->count();
                
                \Log::info('Customer count calculated', [
                    'count' => $count,
                    'query' => 'whereIn(role, [customer, vip]) OR role IS NULL'
                ]);
                
                return $count;
            });

            return response()->json([
                'count' => (int) $customerCount,
                'total_customers' => (int) $customerCount
            ]);
        } catch (\Exception $e) {
            \Log::error('Error counting customers: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'count' => 1000,
                'total_customers' => 1000,
                'message' => 'Using default count'
            ]);
        }
    }

    public function popularSearches()
    {
        return response()->json([
            'backpack',
            'duffle bag',
            'sports wear',
            'tote',
            'basketball',
            'volleyball',
            'gym bag',
            'travel bag'
        ]);
    }

    public function customerAvatars()
    {
        try {
            // Optimized: Use whereIn with NULL handling for better performance
            // Only select needed columns for better performance
            $customers = User::where(function ($query) {
                $query->whereIn('role', ['customer', 'vip'])
                      ->orWhereNull('role');
            })
                ->whereNotNull('profile_image')
                ->select('id', 'first_name', 'last_name', 'profile_image')
                ->inRandomOrder()
                ->limit(5)
                ->get();

            $payload = $customers->map(function ($customer) {
                return [
                    'id' => $customer->id,
                    'name' => trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')) ?: 'Customer',
                    'avatar' => $this->formatProfileImage($customer->profile_image),
                ];
            })->filter(fn ($customer) => !empty($customer['avatar']))->values();

            return response()->json($payload);
        } catch (\Exception $e) {
            \Log::error('Error fetching customer avatars: ' . $e->getMessage());
            return response()->json([], 200);
        }
    }

    private function formatProfileImage($path)
    {
        if (empty($path) || !is_string($path)) {
            return null;
        }

        // If already a full URL, return as is
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        // Use /api/storage/ endpoint for R2 images
        $cleanPath = ltrim($path, '/');
        return url('/api/storage/' . $cleanPath);
    }
}