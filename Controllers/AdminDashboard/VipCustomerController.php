<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class VipCustomerController extends Controller
{
    /**
     * List all VIP customers
     */
    public function index(Request $request)
    {
        try {
            $limit = $request->query('limit', 5);
            $limit = min((int) $limit, 100);
            $search = $request->query('search');
            
            $query = User::where('role', 'vip')
                ->select([
                    'id', 'first_name', 'last_name', 'email', 'phone',
                    'is_organization', 'organization_name', 'profile_image',
                    'created_at', 'updated_at'
                ]);

            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"])
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%")
                      ->orWhere('organization_name', 'like', "%{$search}%");
                });
            }

            $vips = $query->orderBy('created_at', 'desc')
                ->paginate($limit);

            $vips->getCollection()->transform(function ($v) {
                $v->display_name = $v->is_organization
                    ? ($v->organization_name ?? '')
                    : trim(($v->first_name ?? '') . ' ' . ($v->last_name ?? ''));
                
                // Use API endpoint for Cloudflare R2 images
                if (!empty($v->profile_image)) {
                    $imagePath = ltrim($v->profile_image, '/');
                    // Remove /storage/ prefix if present
                    if (strpos($imagePath, 'storage/') === 0) {
                        $imagePath = substr($imagePath, 8);
                    }
                    // Use API endpoint to serve images from R2 (handles R2 URLs properly)
                    // This avoids 400 errors from direct R2 URLs
                    $v->profile_picture = url('/api/storage/' . $imagePath);
                } else {
                    $v->profile_picture = null;
                }

                return $v;
            });

            return response()->json($vips);
        } catch (\Exception $e) {
            Log::error('Error fetching VIPs: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            // Return empty pagination object on error
            return response()->json([]);
        }
    }

    /**
     * Remove VIP status from a customer
     */
    public function remove($id)
    {
        $customer = User::findOrFail($id);

        if ($customer->role !== 'vip') {
            return response()->json(['message' => 'User is not a VIP'], 400);
        }

        $customer->role = 'customer';
        $customer->save();

        // Clear customer count cache since a VIP was demoted
        \Cache::forget('customers_count');

        $customer->display_name = $customer->is_organization
            ? $customer->organization_name
            : trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));

        // Use API endpoint for Cloudflare R2 images
        if ($customer->profile_image) {
            $imagePath = ltrim($customer->profile_image, '/');
            // Use API endpoint to serve images from R2 (handles R2 URLs properly)
            // This avoids 400 errors from direct R2 URLs
            $customer->profile_picture = url('/api/storage/' . $imagePath);
        } else {
            $customer->profile_picture = null;
        }

        return response()->json($customer);
    }
}

