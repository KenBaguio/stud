<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\NotificationService;

class CustomerController extends Controller
{
    // GET /api/admin/customers
    public function index(Request $request)
    {
        $search = $request->query('search', '');
        $limit = $request->query('limit', 5);
        $limit = min((int) $limit, 100);

        $query = User::where(function ($query) {
                $query->whereNotIn('role', ['admin', 'clerk'])
                      ->orWhereNull('role'); // include customers without role
            })
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->whereRaw("COALESCE(display_name, CONCAT_WS(' ', first_name, last_name)) LIKE ?", ["%{$search}%"])
                      ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('id', 'desc');

        $customers = $query->paginate($limit);

        $customers->getCollection()->transform(function ($c) {
            // Ensure display_name is always set
            $c->display_name = $c->display_name ?? trim("{$c->first_name} {$c->last_name}") ?: $c->email;

            // Ensure is_organization is boolean
            $c->is_organization = $c->is_organization ?? false;

            // Fix profile picture path - Use API endpoint for Cloudflare R2
            if ($c->profile_image) {
                $imagePath = ltrim($c->profile_image, '/');
                $c->profile_picture = url('/api/storage/' . $imagePath);
            } else {
                $c->profile_picture = null;
            }

            // Optional: defaults for missing fields
            $c->phone = $c->phone ?? null;
            $c->address = $c->address ?? null;
            $c->dob = $c->dob ?? null;
            $c->date_founded = $c->date_founded ?? null;
            $c->business_type = $c->business_type ?? null;
            $c->industry = $c->industry ?? null;
            $c->total_orders = $c->total_orders ?? 0;
            $c->total_spent = $c->total_spent ?? 0;

            return $c;
        });

        return response()->json($customers);
    }

    // PUT /api/admin/customers/{id}/promote
    public function promote($id)
    {
        $customer = User::whereNotIn('role', ['admin', 'clerk'])->findOrFail($id);

        if ($customer->role === 'vip') {
            return response()->json(['message' => 'Customer is already VIP'], 400);
        }

        $customer->role = 'vip';
        $customer->save();

        // Clear customer count cache since a customer was promoted to VIP
        \Cache::forget('customers_count');

        // Notify customer about their VIP promotion
        try {
            NotificationService::notifyVipPromotion($customer);
        } catch (\Exception $notificationError) {
            Log::warning("Failed to send VIP promotion notification", [
                'customer_id' => $customer->id,
                'error' => $notificationError->getMessage(),
            ]);
        }

        // Return customer with full profile_picture URL - Use API endpoint for Cloudflare R2
        if ($customer->profile_image) {
            $imagePath = ltrim($customer->profile_image, '/');
            // Use API endpoint to serve images from R2 (handles R2 URLs properly)
            // This avoids 400 errors from direct R2 URLs
            $customer->profile_picture = url('/api/storage/' . $imagePath);
        } else {
            $customer->profile_picture = null;
        }

        return response()->json(['message' => 'Customer promoted to VIP', 'customer' => $customer]);
    }

    // GET /api/admin/customers/{id}/purchase-stats
    public function getPurchaseStats($id)
    {
        try {
            $customer = User::whereNotIn('role', ['admin', 'clerk'])->findOrFail($id);

            Log::info("Fetching purchase stats for customer ID: {$id}");

            // Optimized: Single query to get both count and sum
            $orderStats = Order::where('user_id', $id)
                ->selectRaw('
                    COUNT(*) as purchase_count,
                    COALESCE(SUM(CASE 
                        WHEN payment_status = "paid" 
                        OR status = "delivered" 
                        OR status = "completed" 
                        OR payment_status = "completed" 
                        THEN total_amount 
                        ELSE 0 
                    END), 0) as paid_total,
                    COALESCE(SUM(total_amount), 0) as total_spent
                ')
                ->first();

            $purchaseCount = $orderStats->purchase_count ?? 0;
            $totalSpent = $orderStats->paid_total > 0 ? $orderStats->paid_total : $orderStats->total_spent;
            
            Log::info("Total orders found: {$purchaseCount}, Total spent: {$totalSpent}");

            Log::info("Final calculated total spent: {$totalSpent}");

            return response()->json([
                'purchase_count' => $purchaseCount,
                'total_spent' => floatval($totalSpent)
            ]);

        } catch (\Exception $e) {
            Log::error("Error fetching purchase stats for customer {$id}: " . $e->getMessage());
            return response()->json([
                'purchase_count' => 0,
                'total_spent' => 0
            ], 500);
        }
    }
}