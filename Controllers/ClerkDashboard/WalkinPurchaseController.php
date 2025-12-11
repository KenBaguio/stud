<?php

namespace App\Http\Controllers\ClerkDashboard;

use App\Http\Controllers\Controller;
use App\Models\WalkinPurchase;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class WalkinPurchaseController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        Log::info('=== WALK-IN PURCHASE REQUEST START ===');
        Log::info('Request data:', $request->all());

        // Validate the request
        $validator = Validator::make($request->all(), [
            'customer.name' => 'required|string|max:255',
            'customer.gmail' => 'required|email|max:255',
            'customer.contact' => 'required|digits:11',
            'product.name' => 'required|string|max:255',
            'product.price' => 'required|numeric|min:0',
            'product.quantity' => 'required|integer|min:1',
            'product.type' => 'required|in:reference,customized',
            'product.category' => 'required|in:apparel,accessory', // Updated validation
            'total_price' => 'required|numeric|min:0'
        ], [
            'customer.name.required' => 'Customer name is required',
            'customer.gmail.required' => 'Customer email is required',
            'customer.gmail.email' => 'Please enter a valid email address',
            'customer.contact.required' => 'Customer contact number is required',
            'product.name.required' => 'Product name is required',
            'product.price.required' => 'Unit price is required',
            'product.price.min' => 'Unit price must be a positive number',
            'product.quantity.required' => 'Quantity is required',
            'product.quantity.min' => 'Quantity must be at least 1',
            'product.category.required' => 'Product category is required',
            'total_price.required' => 'Total price calculation is required'
        ]);

        if ($validator->fails()) {
            Log::error('Validation failed:', $validator->errors()->toArray());
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Format customer name: capitalize first letter of each word (Title Case)
            $customerName = $request->input('customer.name');
            $customerName = ucwords(strtolower(trim($customerName)));
            
            // Prepare data for creation
            $purchaseData = [
                'customer_name' => $customerName,
                'customer_email' => $request->input('customer.gmail'),
                'customer_contact' => $request->input('customer.contact'),
                'product_name' => $request->input('product.name'),
                'unit_price' => (float) $request->input('product.price'),
                'quantity' => (int) $request->input('product.quantity'),
                'total_price' => (float) $request->input('total_price'),
                'item_type' => $request->input('product.type'),
                'category' => $request->input('product.category')
            ];

            // Create walk-in purchase record
            $walkinPurchase = WalkinPurchase::create($purchaseData);

            // Send notification to admins and clerks about new walk-in purchase
            NotificationService::notifyWalkinPurchaseCreated($walkinPurchase);

            return response()->json([
                'success' => true,
                'message' => 'Walk-in purchase recorded successfully!',
                'data' => [
                    'purchase_id' => $walkinPurchase->id,
                    'customer_name' => $walkinPurchase->customer_name,
                    'total_amount' => $walkinPurchase->total_price,
                    'category' => $walkinPurchase->category,
                    'receipt_number' => 'RC-' . str_pad($walkinPurchase->id, 6, '0', STR_PAD_LEFT)
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Walk-in purchase error:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to record purchase. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get all walk-in purchases (for admin viewing)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $purchases = WalkinPurchase::orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $purchases
            ]);

        } catch (\Exception $e) {
            Log::error('Walk-in purchase fetch error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch walk-in purchases'
            ], 500);
        }
    }

    /**
     * Get specific walk-in purchase details
     */
    public function show($id): JsonResponse
    {
        try {
            $purchase = WalkinPurchase::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $purchase
            ]);

        } catch (\Exception $e) {
            Log::error('Walk-in purchase show error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Purchase not found'
            ], 404);
        }
    }

    /**
     * Get walk-in purchase statistics for dashboard
     */
    public function getStats(): JsonResponse
    {
        try {
            $totalPurchases = WalkinPurchase::count();
            $totalRevenue = WalkinPurchase::sum('total_price');
            $todayPurchases = WalkinPurchase::whereDate('created_at', today())->count();
            $uniqueCustomers = WalkinPurchase::distinct('customer_email')->count('customer_email');
            
            // Category breakdown - removed gear
            $categoryStats = WalkinPurchase::select('category')
                ->selectRaw('COUNT(*) as count')
                ->selectRaw('SUM(total_price) as revenue')
                ->groupBy('category')
                ->get();

            $apparelRevenue = $categoryStats->where('category', 'apparel')->first()->revenue ?? 0;
            $accessoryRevenue = $categoryStats->where('category', 'accessory')->first()->revenue ?? 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'total_purchases' => $totalPurchases,
                    'total_revenue' => $totalRevenue,
                    'today_purchases' => $todayPurchases,
                    'unique_customers' => $uniqueCustomers,
                    'apparel_revenue' => $apparelRevenue,
                    'accessory_revenue' => $accessoryRevenue,
                    'category_breakdown' => $categoryStats
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Walk-in purchase stats error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch purchase statistics'
            ], 500);
        }
    }
}