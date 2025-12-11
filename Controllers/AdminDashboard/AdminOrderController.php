<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminOrderController extends Controller
{
    /**
     * Get all orders for admin management
     */
    public function index(Request $request)
    {
        Log::info('AdminOrderController: index method called');
        
        try {
            // Check if user is authenticated
            $user = auth('api')->user();
            
            if (!$user) {
                Log::warning('AdminOrderController: Unauthorized access attempt');
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            Log::info('AdminOrderController: Fetching orders for user ID: ' . $user->id);

            // Get limit from request (default 5, max 100)
            $limit = $request->query('limit', 5);
            $limit = min((int) $limit, 100);

            $query = Order::with([
                'items.product', 
                'items.customProposal', 
                'voucher', 
                'user'
            ]);

            // Status Filter
            if ($status = $request->query('status')) {
                if ($status !== 'all') {
                    $query->where('status', $status);
                }
            }

            // Search Filter
            if ($search = $request->query('search')) {
                $query->where(function($q) use ($search) {
                    $q->where('id', 'like', "%{$search}%")
                      ->orWhere('total_amount', 'like', "%{$search}%")
                      // Search in related user
                      ->orWhereHas('user', function($u) use ($search) {
                          $u->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                      })
                      // Search in JSON customer data (if used)
                      ->orWhere('customer', 'like', "%{$search}%");
                });
            }

            $orders = $query->orderBy('created_at', 'desc')->paginate($limit);

            Log::info('AdminOrderController: Found ' . $orders->total() . ' orders');

            // Transform the orders to include customer information
            $orders->getCollection()->transform(function ($order) {
                return $this->transformOrder($order);
            });

            return response()->json([
                'success' => true,
                'data' => $orders->items(),
                'total' => $orders->total(),
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage()
            ]);

        } catch (\Exception $e) {
            Log::error('AdminOrderController Error: ' . $e->getMessage());
            Log::error('AdminOrderController Stack Trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch orders: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get specific order details for admin
     */
    public function show($id)
    {
        Log::info('AdminOrderController: show method called for order ID: ' . $id);
        
        try {
            $user = auth('api')->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $order = Order::with(['items.product', 'items.customProposal', 'voucher', 'user'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $this->transformOrder($order)
            ]);

        } catch (\Exception $e) {
            Log::error('AdminOrderController Show Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }
    }

    /**
     * Update order status (admin)
     */
    public function updateStatus(Request $request, $id)
    {
        Log::info('AdminOrderController: updateStatus method called for order ID: ' . $id);
        
        try {
            $user = auth('api')->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            // Allowed statuses exclude 'cancelled'
            $request->validate([
                'status' => 'required|string|in:pending,confirmed,processing,packaging,on_delivery,delivered'
            ]);

            $order = Order::findOrFail($id);
            $oldStatus = $order->status;
            $order->status = $request->status;
            $order->save();

            Log::info("Order {$id} status updated to: {$request->status}");

            // Send notification to customer about order status update
            if ($oldStatus !== $request->status) {
                NotificationService::notifyOrderStatusUpdated($order, $oldStatus, $request->status);
            }

            return response()->json([
                'success' => true,
                'message' => 'Order status updated successfully',
                'data' => $this->transformOrder($order)
            ]);

        } catch (\Exception $e) {
            Log::error('AdminOrderController UpdateStatus Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update order status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Transform order data for admin response - ENHANCED to handle custom proposals
     */
    private function transformOrder($order)
    {
        try {
            $customerData = [];
            
            // Extract customer information from the customer JSON field
            if ($order->customer) {
                if (is_string($order->customer)) {
                    try {
                        $customerData = json_decode($order->customer, true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            $customerData = [];
                        }
                    } catch (\Exception $e) {
                        Log::warning('Failed to parse customer JSON for order ' . $order->id . ': ' . $e->getMessage());
                        $customerData = [];
                    }
                } elseif (is_array($order->customer)) {
                    $customerData = $order->customer;
                }
            }

            // Safely get items data - ENHANCED to handle custom proposals
            $items = [];
            if ($order->items) {
                $items = $order->items->map(function ($item) {
                    $productData = null;
                    $customProposalData = null;
                    
                    // Handle regular product - ensure images use Cloudflare R2
                    if ($item->product) {
                        $images = [];
                        if ($item->product->images) {
                            $rawImages = is_string($item->product->images) ? json_decode($item->product->images, true) : $item->product->images;
                            if (is_array($rawImages)) {
                                foreach ($rawImages as $img) {
                                    if ($img) {
                                        $imagePath = ltrim($img, '/');
                                        // Remove /storage/ prefix if present
                                        if (strpos($imagePath, 'storage/') === 0) {
                                            $imagePath = substr($imagePath, 8);
                                        }
                                        try {
                                            // Use API endpoint for R2 images instead of direct R2 URLs
                                            $images[] = url('/api/storage/' . $imagePath);
                                        } catch (\Exception $e) {
                                            $images[] = $img;
                                        }
                                    }
                                }
                            }
                        }
                        
                        $mainImage = $item->product->image ?? '';
                        if ($mainImage) {
                            $imagePath = ltrim($mainImage, '/');
                            // Remove /storage/ prefix if present
                            if (strpos($imagePath, 'storage/') === 0) {
                                $imagePath = substr($imagePath, 8);
                            }
                            try {
                                // Use API endpoint for R2 images instead of direct R2 URLs
                                $mainImage = url('/api/storage/' . $imagePath);
                            } catch (\Exception $e) {
                                // Keep original if URL generation fails
                            }
                        }
                        
                        $productData = [
                            'id' => $item->product->id,
                            'name' => $item->product->name,
                            'description' => $item->product->description ?? '',
                            'price' => floatval($item->product->price ?? 0),
                            'color' => $item->product->color ?? '',
                            'images' => $images,
                            'image' => $mainImage,
                        ];
                    }
                    
                    // Handle custom proposal - ensure images use Cloudflare R2
                    if ($item->customProposal) {
                        $images = [];
                        if ($item->customProposal->images) {
                            $rawImages = is_string($item->customProposal->images) ? json_decode($item->customProposal->images, true) : $item->customProposal->images;
                            if (is_array($rawImages)) {
                                foreach ($rawImages as $img) {
                                    if ($img) {
                                        $imagePath = ltrim($img, '/');
                                        // Remove /storage/ prefix if present
                                        if (strpos($imagePath, 'storage/') === 0) {
                                            $imagePath = substr($imagePath, 8);
                                        }
                                        try {
                                            // Use API endpoint for R2 images instead of direct R2 URLs
                                            $images[] = url('/api/storage/' . $imagePath);
                                        } catch (\Exception $e) {
                                            $images[] = $img;
                                        }
                                    }
                                }
                            }
                        }
                        
                        $customProposalData = [
                            'id' => $item->customProposal->id,
                            'name' => $item->customProposal->name,
                            'category' => $item->customProposal->category,
                            'material' => $item->customProposal->material,
                            'customization_request' => $item->customProposal->customization_request,
                            'images' => $images,
                            'total_price' => floatval($item->customProposal->total_price ?? 0),
                        ];
                    }

                    return [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'custom_proposal_id' => $item->custom_proposal_id,
                        'name' => $item->name,
                        'price' => floatval($item->price ?? 0),
                        'size_price' => $item->size_price ? floatval($item->size_price) : null,
                        'quantity' => $item->quantity,
                        'size' => $item->size,
                        'image' => $item->image,
                        'is_customized' => $item->is_customized,
                        'product' => $productData,
                        'custom_proposal' => $customProposalData
                    ];
                });
            }

            // Safely get voucher data
            $voucherData = null;
            if ($order->voucher) {
                $voucherData = [
                    'id' => $order->voucher->id,
                    'name' => $order->voucher->name,
                    'percent' => $order->voucher->percent,
                ];
            }

            // Safely get user data
            $userData = null;
            if ($order->user) {
                $userData = [
                    'id' => $order->user->id,
                    'name' => $order->user->name,
                    'email' => $order->user->email,
                ];
            }

            return [
                'id' => $order->id,
                'order_number' => $order->id,
                'user_id' => $order->user_id,
                'customer' => $customerData,
                'subtotal' => floatval($order->subtotal ?? 0),
                'shipping_fee' => floatval($order->shipping_fee ?? 0),
                'total_amount' => floatval($order->total_amount ?? 0),
                'voucher_id' => $order->voucher_id,
                'voucher_code' => $order->voucher_code,
                'discount_amount' => floatval($order->discount_amount ?? 0),
                'payment_method' => $order->payment_method,
                'payment_status' => $order->payment_status,
                'status' => $order->status,
                'created_at' => $order->created_at,
                'updated_at' => $order->updated_at,
                'items' => $items,
                'voucher' => $voucherData,
                'user' => $userData
            ];

        } catch (\Exception $e) {
            Log::error('TransformOrder Error for order ' . ($order->id ?? 'unknown') . ': ' . $e->getMessage());
            return [
                'id' => $order->id ?? 0,
                'order_number' => $order->id ?? 0,
                'user_id' => $order->user_id ?? 0,
                'customer' => [],
                'subtotal' => 0,
                'shipping_fee' => 0,
                'total_amount' => 0,
                'status' => $order->status ?? 'unknown',
                'created_at' => $order->created_at ?? now(),
                'items' => []
            ];
        }
    }
}