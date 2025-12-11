<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Voucher;
use App\Models\UserVoucher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use App\Helpers\R2Helper;

class VoucherController extends Controller
{
    // List all vouchers
    public function index(Request $request)
    {
        $limit = $request->query('limit', 5);
        $limit = min((int) $limit, 100);
        
        $vouchers = Voucher::paginate($limit);

        // Transform the collection within pagination
        $vouchers->getCollection()->transform(function ($voucher) {
            $voucher->is_expired = false;
            $voucher->expires_at = null;
            
            // Ensure image uses Cloudflare R2 via API endpoint
            if ($voucher->image) {
                $imagePath = ltrim($voucher->image, '/');
                $voucher->image_url = url('/api/storage/' . $imagePath);
            } else {
                $voucher->image_url = null;
            }
            
            return $voucher;
        });

        return response()->json($vouchers);
    }

    // Get enabled vouchers for public banner
    public function enabledVouchers(Request $request)
    {
        try {
            \Log::info('Fetching enabled vouchers for public banner...');
            
            $query = Voucher::where('status', 'enabled');
            
            // Add limit if provided
            $limit = $request->query('limit', 5);
            $limit = min((int) $limit, 50);
            
            $vouchers = $query
                ->where(function($query) {
                    $query->whereNull('expires_at')
                          ->orWhere('expires_at', '>', now());
                })
                ->select([
                    'id', 
                    'name', 
                    'description', 
                    'percent as discount_percentage', 
                    'image', 
                    'expires_at',
                    'expiration_type',
                    'expiration_duration'
                ])
                ->paginate($limit);
                
            $vouchers->getCollection()->transform(function($voucher) {
                return [
                    'id' => $voucher->id,
                    'name' => $voucher->name,
                    'description' => $voucher->description,
                    'discount_percentage' => $voucher->discount_percentage,
                    'image' => $voucher->image ? url('/api/storage/' . ltrim($voucher->image, '/')) : null,
                    'expires_at' => $voucher->expires_at,
                    'expiration_type' => $voucher->expiration_type,
                    'expiration_duration' => $voucher->expiration_duration,
                    'link' => '/products' // Default link to products page
                ];
            });

            \Log::info('Successfully fetched ' . $vouchers->count() . ' enabled vouchers');
            
            return response()->json($vouchers);
            
        } catch (\Exception $e) {
            \Log::error('Failed to fetch enabled vouchers: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            
            // Return empty pagination structure instead of 500 error
            return response()->json([
                'current_page' => 1,
                'data' => [],
                'first_page_url' => '',
                'from' => null,
                'last_page' => 1,
                'last_page_url' => '',
                'next_page_url' => null,
                'path' => '',
                'per_page' => 5,
                'prev_page_url' => null,
                'to' => null,
                'total' => 0
            ]);
        }
    }

    // Create a new voucher
    public function store(Request $request)
    {
        $request->validate([
            'name'                => 'required|string|max:255',
            'description'         => 'nullable|string',
            'type'               => 'nullable|string|in:product_discount,free_shipping,shipping_discount',
            'percent'             => 'required|numeric|min:0|max:100',
            'image'               => 'nullable|image|max:2048',
            'expiration_type'     => 'required|string|in:hours,days',
            'expiration_duration' => 'required|numeric|min:1',
        ]);

        $voucherType = $request->type ?? 'product_discount';
        $percent = $voucherType === 'free_shipping' ? 0 : $request->percent;

        $disk = R2Helper::getStorageDisk();
        $imagePath = $request->file('image')?->store('vouchers', $disk);

        $voucher = Voucher::create([
            'name'                => $request->name,
            'description'         => $request->description,
            'type'               => $voucherType,
            'percent'             => $percent,
            'image'               => $imagePath,
            'status'              => 'enabled',
            'expiration_type'     => $request->expiration_type,
            'expiration_duration' => (int) $request->expiration_duration,
        ]);

        return response()->json(['message' => 'Voucher created successfully.', 'voucher' => $voucher]);
    }

    // Update voucher
    public function update(Request $request, $id)
    {
        $voucher = Voucher::findOrFail($id);

        $request->validate([
            'name'                => 'required|string|max:255',
            'description'         => 'nullable|string',
            'type'               => 'nullable|string|in:product_discount,free_shipping,shipping_discount',
            'percent'             => 'required|numeric|min:0|max:100',
            'image'               => 'nullable|image|max:2048',
            'expiration_type'     => 'required|string|in:hours,days',
            'expiration_duration' => 'required|numeric|min:1',
        ]);

        $voucherType = $request->type ?? $voucher->type ?? 'product_discount';
        $percent = $voucherType === 'free_shipping' ? 0 : $request->percent;

        // Handle image update (optional)
        if ($request->hasFile('image')) {
            $disk = R2Helper::getStorageDisk();
            if ($voucher->image) {
                try {
                    Storage::disk($disk)->delete($voucher->image);
                } catch (\Exception $e) {
                    \Log::warning('Failed to delete old voucher image: ' . $e->getMessage());
                }
            }
            try {
                $voucher->image = $request->file('image')->store('vouchers', $disk);
            } catch (\Exception $e) {
                \Log::error('Failed to upload voucher image: ' . $e->getMessage());
                return response()->json(['message' => 'Failed to upload image'], 500);
            }
        }

        $voucher->update([
            'name'                => $request->name,
            'description'         => $request->description,
            'type'               => $voucherType,
            'percent'             => $percent,
            'expiration_type'     => $request->expiration_type,
            'expiration_duration' => (int) $request->expiration_duration,
        ]);

        return response()->json(['message' => 'Voucher updated successfully.', 'voucher' => $voucher]);
    }

    // Send voucher to a user
    public function send(Request $request, $id)
    {
        $request->validate([
            'customer_id' => 'required|exists:users,id',
        ]);

        $voucher = Voucher::findOrFail($id);
        $user = User::findOrFail($request->customer_id);

        $sentAt = now();
        $expiresAt = $voucher->expiration_type === 'hours'
            ? $sentAt->copy()->addHours($voucher->expiration_duration)
            : $sentAt->copy()->addDays($voucher->expiration_duration);

        $userVoucher = UserVoucher::create([
            'user_id' => $user->id,
            'voucher_id' => $voucher->id,
            'voucher_code' => $voucher->generateVoucherCode(),
            'sent_at' => $sentAt,
            'expires_at' => $expiresAt,
        ]);

        // Send notification to customer
        try {
            \App\Services\NotificationService::notifyVoucherSent($userVoucher, $voucher);
        } catch (\Exception $e) {
            \Log::error("Error sending voucher notification: " . $e->getMessage());
            // Don't fail the request if notification fails
        }

        // Broadcast real-time event to customer
        try {
            event(new \App\Events\VoucherSent($userVoucher, $voucher));
        } catch (\Exception $e) {
            \Log::error("Error broadcasting voucher sent event: " . $e->getMessage());
            // Don't fail the request if broadcast fails
        }

        return response()->json([
            'message' => 'Voucher sent successfully.',
            'voucher_instance' => [
                'id' => $userVoucher->id,
                'voucher_code' => $userVoucher->voucher_code,
                'expires_at' => $expiresAt,
            ],
        ]);
    }

    // Toggle enable/disable
    public function toggleStatus($id)
    {
        $voucher = Voucher::findOrFail($id);
        $oldStatus = $voucher->status;
        $newStatus = $oldStatus === 'enabled' ? 'disabled' : 'enabled';
        
        $voucher->status = $newStatus;
        $voucher->save();

        $notificationCount = 0;
        $broadcastCount = 0;
        
        // Get all users who have this voucher and haven't used it yet
        $userVouchers = UserVoucher::where('voucher_id', $voucher->id)
            ->whereNull('used_at')
            ->with('user')
            ->get();
        
        // Broadcast real-time update to all users who have this voucher
        foreach ($userVouchers as $userVoucher) {
            if ($userVoucher->user) {
                try {
                    event(new \App\Events\VoucherStatusChanged($voucher, $newStatus, $userVoucher->user->id));
                    $broadcastCount++;
                } catch (\Exception $e) {
                    \Log::error("VoucherController: Failed to broadcast voucher status change to user {$userVoucher->user->id}: " . $e->getMessage());
                }
            }
        }
        
        // Notify all users who have this voucher when status changes
        if ($newStatus === 'disabled') {
            // If voucher was disabled, notify all users who have this voucher
            try {
                $notificationCount = \App\Services\NotificationService::notifyVoucherDisabled($voucher);
                \Log::info("VoucherController: Voucher disabled, notifications sent to {$notificationCount} users, broadcasts sent to {$broadcastCount} users", [
                    'voucher_id' => $voucher->id,
                    'voucher_name' => $voucher->name
                ]);
            } catch (\Exception $e) {
                \Log::error("VoucherController: Failed to send voucher disabled notifications: " . $e->getMessage());
                // Don't fail the request if notification fails
            }
        } elseif ($newStatus === 'enabled') {
            // If voucher was enabled, notify all users who have this voucher
            try {
                $notificationCount = \App\Services\NotificationService::notifyVoucherEnabled($voucher);
                \Log::info("VoucherController: Voucher enabled, notifications sent to {$notificationCount} users, broadcasts sent to {$broadcastCount} users", [
                    'voucher_id' => $voucher->id,
                    'voucher_name' => $voucher->name
                ]);
            } catch (\Exception $e) {
                \Log::error("VoucherController: Failed to send voucher enabled notifications: " . $e->getMessage());
                // Don't fail the request if notification fails
            }
        }

        return response()->json([
            'message' => 'Voucher status updated.', 
            'voucher' => $voucher,
            'notifications_sent' => $notificationCount,
            'broadcasts_sent' => $broadcastCount
        ]);
    }
}