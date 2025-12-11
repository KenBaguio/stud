<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\WalkinPurchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request; // Add this import
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class WalkinPurchaseController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $purchases = WalkinPurchase::orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $purchases
            ]);

        } catch (\Exception $e) {
            Log::error('Admin walk-in purchase fetch error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch walk-in purchases'
            ], 500);
        }
    }

    public function getStats(): JsonResponse
    {
        try {
            $currentMonth = now()->month;
            $currentYear = now()->year;

            // Total walk-in revenue with breakdown
            $totalRevenue = WalkinPurchase::sum('total_price');
            $referenceRevenue = WalkinPurchase::where('product_type', 'reference')->sum('total_price');
            $customizedRevenue = WalkinPurchase::where('product_type', 'customized')->sum('total_price');

            // This month's walk-in revenue with breakdown
            $monthlyRevenue = WalkinPurchase::whereYear('created_at', $currentYear)
                ->whereMonth('created_at', $currentMonth)
                ->sum('total_price');
            $monthlyReference = WalkinPurchase::whereYear('created_at', $currentYear)
                ->whereMonth('created_at', $currentMonth)
                ->where('product_type', 'reference')
                ->sum('total_price');
            $monthlyCustomized = WalkinPurchase::whereYear('created_at', $currentYear)
                ->whereMonth('created_at', $currentMonth)
                ->where('product_type', 'customized')
                ->sum('total_price');

            // Total walk-in orders count with breakdown
            $totalOrders = WalkinPurchase::count();
            $referenceOrders = WalkinPurchase::where('product_type', 'reference')->count();
            $customizedOrders = WalkinPurchase::where('product_type', 'customized')->count();

            // Unique walk-in customers count
            $uniqueCustomers = WalkinPurchase::distinct('customer_email')->count('customer_email');

            // Recent walk-in purchases (last 7 days)
            $recentPurchases = WalkinPurchase::where('created_at', '>=', now()->subDays(7))
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'total_revenue' => (float) $totalRevenue,
                    'reference_revenue' => (float) $referenceRevenue,
                    'customized_revenue' => (float) $customizedRevenue,
                    'monthly_revenue' => (float) $monthlyRevenue,
                    'monthly_reference' => (float) $monthlyReference,
                    'monthly_customized' => (float) $monthlyCustomized,
                    'total_orders' => $totalOrders,
                    'reference_orders' => $referenceOrders,
                    'customized_orders' => $customizedOrders,
                    'unique_customers' => $uniqueCustomers,
                    'recent_purchases' => $recentPurchases,
                    'revenue_breakdown' => [
                        'reference_percentage' => $totalRevenue > 0 ? ($referenceRevenue / $totalRevenue) * 100 : 0,
                        'customized_percentage' => $totalRevenue > 0 ? ($customizedRevenue / $totalRevenue) * 100 : 0
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Admin walk-in stats error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch walk-in statistics'
            ], 500);
        }
    }

    /**
     * Get walk-in purchase analytics with date range
     */
    public function getAnalytics(Request $request): JsonResponse // Now this will work
    {
        try {
            $startDate = $request->get('start_date', Carbon::now()->subMonth());
            $endDate = $request->get('end_date', Carbon::now());

            // Revenue breakdown by product type
            $revenueByType = WalkinPurchase::whereBetween('created_at', [$startDate, $endDate])
                ->select('product_type', DB::raw('SUM(total_price) as revenue'))
                ->groupBy('product_type')
                ->get()
                ->pluck('revenue', 'product_type');

            // Daily revenue trend
            $dailyRevenue = WalkinPurchase::whereBetween('created_at', [$startDate, $endDate])
                ->select(
                    DB::raw('DATE(created_at) as date'),
                    DB::raw('SUM(total_price) as revenue'),
                    'product_type'
                )
                ->groupBy('date', 'product_type')
                ->orderBy('date')
                ->get();

            // Top products by revenue
            $topProducts = WalkinPurchase::whereBetween('created_at', [$startDate, $endDate])
                ->select(
                    'product_name',
                    'product_type',
                    DB::raw('SUM(total_price) as revenue'),
                    DB::raw('SUM(quantity) as total_quantity'),
                    DB::raw('COUNT(*) as order_count')
                )
                ->groupBy('product_name', 'product_type')
                ->orderBy('revenue', 'desc')
                ->limit(5)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'revenue_by_type' => $revenueByType,
                    'daily_revenue' => $dailyRevenue,
                    'top_products' => $topProducts,
                    'date_range' => [
                        'start' => $startDate,
                        'end' => $endDate
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Walk-in analytics error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch analytics'
            ], 500);
        }
    }
}