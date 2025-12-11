<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Order;
use App\Models\WalkinPurchase;
use App\Models\Product;
use App\Models\Voucher;
use App\Models\Category;
use App\Models\Material;
use App\Models\CustomProposal;
use Illuminate\Support\Facades\Log;

class AdminSalesController extends Controller
{
    /**
     * Get dashboard metrics with revenue breakdown - FIXED for custom proposals
     */
    public function dashboard(Request $request)
    {
        try {
            $dateRange = $this->getDateRange($request);
            
            // Total Revenue (online + walk-in) with breakdown
            $onlineRevenue = $this->getOnlineRevenue($dateRange);
            $walkinRevenue = $this->getWalkinRevenue($dateRange);
            $totalRevenue = $onlineRevenue + $walkinRevenue;
            
            // Revenue breakdown between reference and customized items - FIXED
            $revenueBreakdown = $this->getRevenueBreakdown($dateRange);
            
            // Previous period revenue for comparison
            $previousRange = $this->getPreviousDateRange($dateRange);
            $previousOnlineRevenue = $this->getOnlineRevenue($previousRange);
            $previousWalkinRevenue = $this->getWalkinRevenue($previousRange);
            $previousTotalRevenue = $previousOnlineRevenue + $previousWalkinRevenue;
            
            // Calculate revenue change percentage
            $revenueChange = $previousTotalRevenue > 0 
                ? (($totalRevenue - $previousTotalRevenue) / $previousTotalRevenue) * 100 
                : 0;

            // Order counts
            $onlineOrders = $this->getOnlineOrdersCount($dateRange);
            $walkinOrders = $this->getWalkinOrdersCount($dateRange);
            
            // Active products count (status = 'active')
            $activeProducts = Product::where('status', 'active')->count();
            
            // Active vouchers count (status = 'enabled')
            $activeVouchers = Voucher::where('status', 'enabled')->count();

            // Total Discounts
            $totalDiscounts = $this->getTotalDiscounts($dateRange);

            return response()->json([
                'success' => true,
                'data' => [
                    'total_revenue' => round($totalRevenue, 2),
                    'revenue_change' => round($revenueChange, 2),
                    'total_orders' => $onlineOrders, // Only online orders for total
                    'online_orders' => $onlineOrders,
                    'walkin_orders' => $walkinOrders,
                    'active_products' => $activeProducts,
                    'active_vouchers' => $activeVouchers,
                    'total_discounts' => round($totalDiscounts, 2),
                    // Enhanced revenue breakdown - FIXED
                    'revenue_breakdown' => $revenueBreakdown,
                    'online_revenue' => round($onlineRevenue, 2),
                    'walkin_revenue' => round($walkinRevenue, 2),
                    'reference_revenue' => round($revenueBreakdown['reference']['total'], 2),
                    'customized_revenue' => round($revenueBreakdown['customized']['total'], 2),
                    'reference_online' => round($revenueBreakdown['reference']['online'], 2),
                    'reference_walkin' => round($revenueBreakdown['reference']['walkin'], 2),
                    'customized_online' => round($revenueBreakdown['customized']['online'], 2),
                    'customized_walkin' => round($revenueBreakdown['customized']['walkin'], 2),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('AdminSales Dashboard Error: ' . $e->getMessage());
            Log::error('AdminSales Dashboard Stack Trace: ' . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load dashboard data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get revenue breakdown between reference and customized items - FIXED VERSION
     */
    private function getRevenueBreakdown($dateRange)
    {
        // Optimized: Online orders revenue breakdown - FIXED CUSTOMIZED DETECTION
        // Use select to reduce data transfer and only get necessary fields
        $onlineOrders = Order::whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->where('status', '!=', 'cancelled')
            ->select('id', 'created_at', 'status')
            ->with(['items' => function($query) {
                $query->select('id', 'order_id', 'quantity', 'price', 'size_price', 'is_customized', 'custom_proposal_id');
            }])
            ->get();

        $onlineReferenceRevenue = 0;
        $onlineCustomizedRevenue = 0;

        foreach ($onlineOrders as $order) {
            foreach ($order->items as $item) {
                $itemTotal = $item->quantity * ($item->size_price ?? $item->price ?? 0);
                
                // Properly detect all customized items (both by flag and by custom_proposal_id)
                if ($this->isItemCustomized($item)) {
                    $onlineCustomizedRevenue += $itemTotal;
                    Log::info("Customized item found - Order: {$order->id}, Item: {$item->id}, Custom Proposal: {$item->custom_proposal_id}, Amount: {$itemTotal}");
                } else {
                    $onlineReferenceRevenue += $itemTotal;
                }
            }
        }

        $onlineRevenue = $onlineReferenceRevenue + $onlineCustomizedRevenue;

        // Walk-in purchases revenue breakdown
        $walkinPurchases = WalkinPurchase::whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->get();

        $walkinReferenceRevenue = $walkinPurchases->where('product_type', 'reference')->sum('total_price');
        $walkinCustomizedRevenue = $walkinPurchases->where('product_type', 'customized')->sum('total_price');
        $walkinRevenue = $walkinReferenceRevenue + $walkinCustomizedRevenue;

        // Totals
        $totalRevenue = $onlineRevenue + $walkinRevenue;
        $referenceRevenue = $onlineReferenceRevenue + $walkinReferenceRevenue;
        $customizedRevenue = $onlineCustomizedRevenue + $walkinCustomizedRevenue;

        // Calculate percentages
        $referencePercentage = $totalRevenue > 0 ? ($referenceRevenue / $totalRevenue) * 100 : 0;
        $customizedPercentage = $totalRevenue > 0 ? ($customizedRevenue / $totalRevenue) * 100 : 0;

        Log::info("Revenue Breakdown - Reference: {$referenceRevenue}, Customized: {$customizedRevenue}, Online Customized: {$onlineCustomizedRevenue}");

        return [
            'reference' => [
                'total' => $referenceRevenue,
                'online' => $onlineReferenceRevenue,
                'walkin' => $walkinReferenceRevenue,
                'percentage' => $referencePercentage
            ],
            'customized' => [
                'total' => $customizedRevenue,
                'online' => $onlineCustomizedRevenue,
                'walkin' => $walkinCustomizedRevenue,
                'percentage' => $customizedPercentage
            ],
            'total' => $totalRevenue
        ];
    }

    /**
     * Enhanced method to detect if an order item is customized
     */
    private function isItemCustomized($item)
    {
        // Method 1: Check the is_customized flag
        if ($item->is_customized) {
            return true;
        }
        
        // Method 2: Check if it has a custom proposal ID (for both apparel and accessories)
        if (!empty($item->custom_proposal_id)) {
            return true;
        }
        
        // Method 3: Check if the item name suggests customization
        if ($item->name && (
            stripos($item->name, 'custom') !== false ||
            stripos($item->name, 'personalized') !== false ||
            stripos($item->name, 'bespoke') !== false
        )) {
            return true;
        }

        return false;
    }

    /**
     * Get top selling products with item type breakdown - FIXED VERSION
     */
    public function topProducts(Request $request)
    {
        try {
            $dateRange = $this->getDateRange($request);
            
            // Regular products (reference items)
            $topReferenceProducts = DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->join('products', 'order_items.product_id', '=', 'products.id')
                ->where('products.status', 'active')
                ->where(function($query) {
                    // Exclude all customized items, not just by flag
                    $query->where('order_items.is_customized', false)
                          ->whereNull('order_items.custom_proposal_id');
                })
                ->whereBetween('orders.created_at', [$dateRange['start'], $dateRange['end']])
                ->where('orders.status', '!=', 'cancelled')
                ->select(
                    'products.id',
                    'products.name',
                    'products.image',
                    'products.images',
                    DB::raw('SUM(order_items.quantity) as total_quantity'),
                    DB::raw('COUNT(DISTINCT orders.id) as total_orders'),
                    DB::raw('SUM(order_items.quantity * COALESCE(order_items.size_price, order_items.price)) as total_revenue'),
                    DB::raw('"reference" as product_type')
                )
                ->groupBy('products.id', 'products.name', 'products.image', 'products.images')
                ->orderBy('total_quantity', 'desc')
                ->limit(5)
                ->get();

            // Custom proposals (customized items) - FIXED QUERY
            $topCustomizedProducts = DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->where(function($query) {
                    // Include all customized items (both by flag and by custom_proposal_id)
                    $query->where('order_items.is_customized', true)
                          ->orWhereNotNull('order_items.custom_proposal_id');
                })
                ->whereBetween('orders.created_at', [$dateRange['start'], $dateRange['end']])
                ->where('orders.status', '!=', 'cancelled')
                ->leftJoin('custom_proposals', 'order_items.custom_proposal_id', '=', 'custom_proposals.id')
                ->leftJoin('products', 'order_items.product_id', '=', 'products.id')
                ->select(
                    DB::raw('COALESCE(custom_proposals.id, products.id) as id'),
                    DB::raw('COALESCE(custom_proposals.name, products.name, order_items.name) as name'),
                    DB::raw('COALESCE(custom_proposals.images, products.images, products.image) as images'),
                    DB::raw('SUM(order_items.quantity) as total_quantity'),
                    DB::raw('COUNT(DISTINCT orders.id) as total_orders'),
                    DB::raw('SUM(order_items.quantity * COALESCE(order_items.size_price, order_items.price)) as total_revenue'),
                    DB::raw('"customized" as product_type')
                )
                ->groupBy('id', 'name', 'images')
                ->orderBy('total_quantity', 'desc')
                ->limit(5)
                ->get();

            // Combine and sort all products
            $allProducts = $topReferenceProducts->merge($topCustomizedProducts)
                ->sortByDesc('total_quantity')
                ->take(5);

            return response()->json([
                'success' => true,
                'data' => $allProducts->map(function ($product) {
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'image' => $this->getPrimaryImage($product->images),
                        'images' => $this->parseJsonField($product->images),
                        'total_quantity' => $product->total_quantity,
                        'total_orders' => $product->total_orders,
                        'total_revenue' => floatval($product->total_revenue),
                        'product_type' => $product->product_type
                    ];
                })
            ]);

        } catch (\Exception $e) {
            Log::error('AdminSales TopProducts Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load top products'
            ], 500);
        }
    }

    /**
     * Helper to get primary image from images field
     */
    private function getPrimaryImage($images)
    {
        if (empty($images)) {
            return null;
        }

        $parsedImages = $this->parseJsonField($images);
        
        if (is_array($parsedImages) && !empty($parsedImages[0])) {
            return $parsedImages[0];
        }

        // If it's a string, assume it's a single image URL
        if (is_string($images)) {
            return $images;
        }

        return null;
    }

    /**
     * Get inventory alerts (low stock materials)
     */
    public function inventoryAlerts(Request $request)
    {
        try {
            // Get materials with low stock (less than or equal to 10)
            $lowStockThreshold = 10;
            $alerts = Material::where('quantity', '<=', $lowStockThreshold)
                ->orderBy('quantity', 'asc')
                ->get()
                ->map(function ($material) use ($lowStockThreshold) {
                    return [
                        'id' => $material->id,
                        'name' => $material->name,
                        'stock_quantity' => $material->quantity,
                        'min_stock' => $lowStockThreshold,
                        'type' => $material->type,
                        'cost' => $material->cost
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $alerts
            ]);

        } catch (\Exception $e) {
            Log::error('AdminSales InventoryAlerts Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load inventory alerts'
            ], 500);
        }
    }

    /**
     * Get recent orders with item type information
     */
    public function recentOrders(Request $request)
    {
        try {
            $limit = $request->get('limit', 5);
            $dateRange = $this->getDateRange($request);
            
            $orders = Order::with(['user', 'voucher', 'items'])
                ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($order) {
                    $customerData = $this->parseCustomerData($order->customer);

                    // Determine if order has custom items - FIXED detection
                    $hasCustomItems = $order->items->contains(function ($item) {
                        return $this->isItemCustomized($item);
                    });
                    $hasReferenceItems = $order->items->contains(function ($item) {
                        return !$this->isItemCustomized($item);
                    });

                    return [
                        'id' => $order->id,
                        'order_number' => $order->id,
                        'type' => 'online',
                        'customer' => $customerData,
                        'first_name' => $customerData['first_name'] ?? '',
                        'last_name' => $customerData['last_name'] ?? '',
                        'total_amount' => floatval($order->total_amount),
                        'total' => floatval($order->total_amount),
                        'status' => $order->status,
                        'created_at' => $order->created_at,
                        'user' => $order->user ? [
                            'id' => $order->user->id,
                            'name' => $order->user->name,
                            'email' => $order->user->email
                        ] : null,
                        'has_custom_items' => $hasCustomItems,
                        'has_reference_items' => $hasReferenceItems,
                        'item_types' => $hasCustomItems && $hasReferenceItems ? 'mixed' : 
                                       ($hasCustomItems ? 'customized' : 'reference')
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $orders
            ]);

        } catch (\Exception $e) {
            Log::error('AdminSales RecentOrders Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load recent orders'
            ], 500);
        }
    }

    /**
     * Get enhanced charts data with revenue breakdown
     */
    public function charts(Request $request)
    {
        try {
            $dateRange = $this->getDateRange($request);
            
            // Revenue Overview Chart Data with breakdown
            $revenueData = $this->getEnhancedRevenueChartData($dateRange);
            
            // Sales by Category Chart Data
            $categoryData = $this->getCategoryChartData($dateRange);

            // Revenue distribution by item type
            $itemTypeData = $this->getItemTypeDistributionData($dateRange);

            return response()->json([
                'success' => true,
                'data' => [
                    'revenue_overview' => $revenueData,
                    'sales_by_category' => $categoryData,
                    'item_type_distribution' => $itemTypeData
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('AdminSales Charts Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load charts data'
            ], 500);
        }
    }

    /**
     * Get enhanced revenue chart data with item type breakdown - FIXED
     */
    private function getEnhancedRevenueChartData($dateRange)
    {
        $period = $this->getChartPeriod($dateRange);
        $labels = [];
        $onlineReferenceData = [];
        $onlineCustomizedData = [];
        $walkinReferenceData = [];
        $walkinCustomizedData = [];

        foreach ($period as $date) {
            $labels[] = $date['label'];
            
            // Online revenue breakdown for this period - FIXED
            $onlineOrders = Order::where('status', '!=', 'cancelled')
                ->whereBetween('created_at', [$date['start'], $date['end']])
                ->with(['items' => function($query) {
                    $query->select('id', 'order_id', 'quantity', 'price', 'size_price', 'is_customized', 'custom_proposal_id');
                }])
                ->get();

            $onlineReference = 0;
            $onlineCustomized = 0;

            foreach ($onlineOrders as $order) {
                foreach ($order->items as $item) {
                    $itemTotal = $item->quantity * ($item->size_price ?? $item->price ?? 0);
                    if ($this->isItemCustomized($item)) {
                        $onlineCustomized += $itemTotal;
                    } else {
                        $onlineReference += $itemTotal;
                    }
                }
            }

            $onlineReferenceData[] = floatval($onlineReference);
            $onlineCustomizedData[] = floatval($onlineCustomized);
            
            // Walk-in revenue breakdown for this period
            $walkinPurchases = WalkinPurchase::whereBetween('created_at', [$date['start'], $date['end']])
                ->get();

            $walkinReference = $walkinPurchases->where('product_type', 'reference')->sum('total_price');
            $walkinCustomized = $walkinPurchases->where('product_type', 'customized')->sum('total_price');

            $walkinReferenceData[] = floatval($walkinReference);
            $walkinCustomizedData[] = floatval($walkinCustomized);
        }

        return [
            'labels' => $labels,
            'online_reference' => $onlineReferenceData,
            'online_customized' => $onlineCustomizedData,
            'walkin_reference' => $walkinReferenceData,
            'walkin_customized' => $walkinCustomizedData,
            'online_total' => array_map(function($ref, $cust) {
                return $ref + $cust;
            }, $onlineReferenceData, $onlineCustomizedData),
            'walkin_total' => array_map(function($ref, $cust) {
                return $ref + $cust;
            }, $walkinReferenceData, $walkinCustomizedData)
        ];
    }

    /**
     * Get item type distribution data
     */
    private function getItemTypeDistributionData($dateRange)
    {
        $breakdown = $this->getRevenueBreakdown($dateRange);
        
        return [
            'labels' => ['Reference Items', 'Customized Items'],
            'data' => [
                round($breakdown['reference']['total'], 2),
                round($breakdown['customized']['total'], 2)
            ],
            'percentages' => [
                round($breakdown['reference']['percentage'], 1),
                round($breakdown['customized']['percentage'], 1)
            ]
        ];
    }

    /**
     * Parse customer data safely (handles both string JSON and array)
     */
    private function parseCustomerData($customer)
    {
        if (is_array($customer)) {
            return $customer;
        }
        
        if (is_string($customer)) {
            try {
                $parsed = json_decode($customer, true);
                return is_array($parsed) ? $parsed : [];
            } catch (\Exception $e) {
                return [];
            }
        }
        
        return [];
    }

    /**
     * Parse JSON field safely
     */
    private function parseJsonField($field)
    {
        if (is_array($field)) {
            return $field;
        }
        
        if (is_string($field)) {
            try {
                $parsed = json_decode($field, true);
                return is_array($parsed) ? $parsed : [];
            } catch (\Exception $e) {
                return [];
            }
        }
        
        return [];
    }

    /**
     * Get category chart data
     */
    private function getCategoryChartData($dateRange)
    {
        $categorySales = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->whereBetween('orders.created_at', [$dateRange['start'], $dateRange['end']])
            ->where('orders.status', '!=', 'cancelled')
            ->select(
                'categories.id',
                'categories.name',
                DB::raw('SUM(order_items.quantity * order_items.price) as total_revenue')
            )
            ->groupBy('categories.id', 'categories.name')
            ->get();

        $labels = [];
        $data = [];

        foreach ($categorySales as $category) {
            $labels[] = $category->name;
            $data[] = floatval($category->total_revenue);
        }

        // If no data, return default
        if (empty($labels)) {
            $labels = ['No Data'];
            $data = [100];
        }

        return [
            'labels' => $labels,
            'data' => $data
        ];
    }

    /**
     * Get date range based on request
     */
    private function getDateRange(Request $request)
    {
        $range = $request->get('range', 'month');
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        if ($range === 'custom' && $startDate && $endDate) {
            return [
                'start' => Carbon::parse($startDate)->startOfDay(),
                'end' => Carbon::parse($endDate)->endOfDay()
            ];
        }

        return $this->getPredefinedRange($range);
    }

    /**
     * Get predefined date ranges
     */
    private function getPredefinedRange($range)
    {
        $now = Carbon::now();

        switch ($range) {
            case 'today':
                return [
                    'start' => $now->copy()->startOfDay(),
                    'end' => $now->copy()->endOfDay()
                ];
            case 'yesterday':
                return [
                    'start' => $now->copy()->subDay()->startOfDay(),
                    'end' => $now->copy()->subDay()->endOfDay()
                ];
            case 'week':
                return [
                    'start' => $now->copy()->startOfWeek(),
                    'end' => $now->copy()->endOfWeek()
                ];
            case 'month':
                return [
                    'start' => $now->copy()->startOfMonth(),
                    'end' => $now->copy()->endOfMonth()
                ];
            case 'quarter':
                return [
                    'start' => $now->copy()->startOfQuarter(),
                    'end' => $now->copy()->endOfQuarter()
                ];
            case 'year':
                return [
                    'start' => $now->copy()->startOfYear(),
                    'end' => $now->copy()->endOfYear()
                ];
            default:
                return [
                    'start' => $now->copy()->startOfMonth(),
                    'end' => $now->copy()->endOfMonth()
                ];
        }
    }

    /**
     * Get previous date range for comparison
     */
    private function getPreviousDateRange($currentRange)
    {
        $duration = $currentRange['end']->diffInDays($currentRange['start']);
        
        return [
            'start' => $currentRange['start']->copy()->subDays($duration),
            'end' => $currentRange['start']->copy()->subDay()
        ];
    }

    /**
     * Get chart period breakdown
     */
    private function getChartPeriod($dateRange)
    {
        $start = $current = $dateRange['start']->copy();
        $end = $dateRange['end']->copy();
        $period = [];
        
        $daysDiff = $start->diffInDays($end);
        
        if ($daysDiff <= 1) {
            // Hourly breakdown for today
            for ($i = 0; $i < 24; $i++) {
                $period[] = [
                    'label' => $current->copy()->addHours($i)->format('H:00'),
                    'start' => $current->copy()->addHours($i),
                    'end' => $current->copy()->addHours($i + 1)->subMinute()
                ];
            }
        } elseif ($daysDiff <= 7) {
            // Daily breakdown for week
            while ($current <= $end) {
                $period[] = [
                    'label' => $current->format('M j'),
                    'start' => $current->copy()->startOfDay(),
                    'end' => $current->copy()->endOfDay()
                ];
                $current->addDay();
            }
        } elseif ($daysDiff <= 31) {
            // Weekly breakdown for month
            $weekStart = $start->copy()->startOfWeek();
            while ($weekStart <= $end) {
                $weekEnd = $weekStart->copy()->endOfWeek();
                if ($weekEnd > $end) $weekEnd = $end;
                
                $period[] = [
                    'label' => 'Week ' . $weekStart->weekOfYear,
                    'start' => $weekStart,
                    'end' => $weekEnd
                ];
                $weekStart->addWeek();
            }
        } else {
            // Monthly breakdown for longer periods
            $monthStart = $start->copy()->startOfMonth();
            while ($monthStart <= $end) {
                $monthEnd = $monthStart->copy()->endOfMonth();
                if ($monthEnd > $end) $monthEnd = $end;
                
                $period[] = [
                    'label' => $monthStart->format('M Y'),
                    'start' => $monthStart,
                    'end' => $monthEnd
                ];
                $monthStart->addMonth();
            }
        }
        
        return $period;
    }

    /**
     * Get online revenue for date range
     */
    private function getOnlineRevenue($dateRange)
    {
        return Order::where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->sum('total_amount') ?? 0;
    }

    /**
     * Get walk-in revenue for date range
     */
    private function getWalkinRevenue($dateRange)
    {
        return WalkinPurchase::whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->sum('total_price') ?? 0;
    }

    /**
     * Get online orders count for date range
     */
    private function getOnlineOrdersCount($dateRange)
    {
        return Order::where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->count();
    }

    /**
     * Get walk-in orders count for date range
     */
    private function getWalkinOrdersCount($dateRange)
    {
        return WalkinPurchase::whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->count();
    }

    /**
     * Get total discounts for date range (Public Endpoint)
     */
    public function totalDiscounts(Request $request)
    {
        try {
            $dateRange = $this->getDateRange($request);
            
            // Use DB facade to ensure we get the raw sum, bypassing model casting/scopes
            $total = DB::table('orders')
                ->where('status', '!=', 'cancelled')
                ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                ->sum('discount_amount');
                
            return response()->json([
                'success' => true,
                'total' => round($total ?? 0, 2),
                'debug_range' => $dateRange // Optional: for debugging
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'total' => 0, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Get total discounts for date range
     */
    private function getTotalDiscounts($dateRange)
    {
        return Order::where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->sum('discount_amount') ?? 0;
    }
}