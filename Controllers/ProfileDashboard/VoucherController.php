<?php

namespace App\Http\Controllers\ProfileDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\UserVoucher;
use App\Models\Voucher;

class VoucherController extends Controller
{
    // Get only active (unused & unexpired) vouchers for the logged-in user
    public function myVouchers(Request $request)
    {
        $user = $request->user();

        // Add query limit support (default 5, max 100)
        $limit = $request->query('limit', 5);
        $limit = min((int) $limit, 100);
        
        $userVouchers = UserVoucher::with('voucher')
            ->where('user_id', $user->id)
            ->whereNull('used_at') // only unused vouchers
            ->orderByDesc('created_at')
            ->paginate($limit);

        // Transform the collection
        $userVouchers->getCollection()->transform(function ($userVoucher) {
            $voucher = $userVoucher->voucher;
            
            // Calculate expiry for this specific instance
            if (!$userVoucher->expires_at && $voucher) {
                $sentAt = $userVoucher->sent_at ?? $userVoucher->created_at;
                $duration = (int) $voucher->expiration_duration;

                $expiresAt = $voucher->expiration_type === 'hours'
                    ? $sentAt->copy()->addHours($duration)
                    : $sentAt->copy()->addDays($duration);

                // Update this specific instance with calculated expiry
                $userVoucher->update(['expires_at' => $expiresAt]);
            }

            // Prepare data for frontend
            return [
                'id' => $userVoucher->id, // Use user_voucher ID, not voucher ID
                'voucher_id' => $voucher->id,
                'voucher_code' => $userVoucher->voucher_code,
                'name' => $voucher->name,
                'description' => $voucher->description,
                'percent' => $voucher->percent,
                'image' => $voucher->image ? url('/api/storage/' . ltrim($voucher->image, '/')) : null,
                'status' => $voucher->status,
                'expires_at' => $userVoucher->expires_at,
                'is_expired' => $userVoucher->isExpired(),
                'sent_at' => $userVoucher->sent_at,
            ];
        });
        
        // Filter out expired vouchers from the current page
        $filteredCollection = $userVouchers->getCollection()->filter(function ($voucherData) {
            return !$voucherData['is_expired'];
        })->values();
        
        $userVouchers->setCollection($filteredCollection);

        return response()->json($userVouchers);
    }

    // Validate voucher during checkout
    public function validateVoucher(Request $request)
    {
        $request->validate([
            'user_voucher_id' => 'required|exists:user_voucher,id',
        ]);

        $user = $request->user();
        $userVoucher = UserVoucher::with('voucher')
            ->where('id', $request->user_voucher_id)
            ->where('user_id', $user->id)
            ->first();

        if (!$userVoucher) {
            return response()->json(['error' => 'Voucher not found'], 404);
        }

        if ($userVoucher->isUsed()) {
            return response()->json(['error' => 'Voucher already used'], 400);
        }

        if ($userVoucher->isExpired()) {
            return response()->json(['error' => 'Voucher expired'], 400);
        }

        $voucher = $userVoucher->voucher;
        if (!$voucher->isActive()) {
            return response()->json(['error' => 'Voucher is not active'], 400);
        }

        return response()->json([
            'valid' => true,
            'voucher' => [
                'user_voucher_id' => $userVoucher->id,
                'voucher_id' => $voucher->id,
                'name' => $voucher->name,
                'percent' => $voucher->percent,
                'voucher_code' => $userVoucher->voucher_code,
            ]
        ]);
    }

    // Get unread/new vouchers (for first-time notification modal)
    public function unreadVouchers(Request $request)
    {
        $user = $request->user();

        // Add query limit support (default 5, max 100)
        $limit = $request->query('limit', 5);
        $limit = min((int) $limit, 100);
        
        $unreadVouchers = UserVoucher::with('voucher')
            ->where('user_id', $user->id)
            ->whereNull('used_at') // not used
            ->whereNull('viewed_at') // not viewed yet
            ->orderByDesc('sent_at')
            ->limit($limit)
            ->get()
            ->map(function ($userVoucher) {
                $voucher = $userVoucher->voucher;
                
                // Calculate expiry for this specific instance
                if (!$userVoucher->expires_at && $voucher) {
                    $sentAt = $userVoucher->sent_at ?? $userVoucher->created_at;
                    $duration = (int) $voucher->expiration_duration;

                    $expiresAt = $voucher->expiration_type === 'hours'
                        ? $sentAt->copy()->addHours($duration)
                        : $sentAt->copy()->addDays($duration);

                    $userVoucher->update(['expires_at' => $expiresAt]);
                }

                // Only return if not expired
                if ($userVoucher->isExpired()) {
                    return null;
                }

                return [
                    'id' => $userVoucher->id,
                    'voucher_id' => $voucher->id,
                    'voucher_code' => $userVoucher->voucher_code,
                    'name' => $voucher->name,
                    'description' => $voucher->description,
                    'percent' => $voucher->percent,
                    'image' => $voucher->image ? url('/api/storage/' . ltrim($voucher->image, '/')) : null,
                    'expires_at' => $userVoucher->expires_at,
                    'sent_at' => $userVoucher->sent_at,
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'has_unread' => $unreadVouchers->isNotEmpty(),
            'vouchers' => $unreadVouchers,
            'count' => $unreadVouchers->count(),
        ]);
    }

    // Get available vouchers for reminder modal (pops up every 3 mins)
    // Shows all available vouchers (even if already viewed) to remind customer
    // Automatically excludes used vouchers - modal will not show if voucher is used
    public function availableVouchersForReminder(Request $request)
    {
        $user = $request->user();

        // Add query limit support (default 5, max 100)
        $limit = $request->query('limit', 5);
        $limit = min((int) $limit, 100);
        
        $availableVouchers = UserVoucher::with('voucher')
            ->where('user_id', $user->id)
            ->whereNull('used_at') // Exclude used vouchers - modal won't show if voucher is used
            ->orderByDesc('sent_at')
            ->limit($limit)
            ->get()
            ->map(function ($userVoucher) {
                $voucher = $userVoucher->voucher;
                
                // Skip if voucher is already used
                if ($userVoucher->isUsed()) {
                    return null;
                }
                
                // Calculate expiry for this specific instance
                if (!$userVoucher->expires_at && $voucher) {
                    $sentAt = $userVoucher->sent_at ?? $userVoucher->created_at;
                    $duration = (int) $voucher->expiration_duration;

                    $expiresAt = $voucher->expiration_type === 'hours'
                        ? $sentAt->copy()->addHours($duration)
                        : $sentAt->copy()->addDays($duration);

                    $userVoucher->update(['expires_at' => $expiresAt]);
                }

                // Only return if not expired and voucher is active
                if ($userVoucher->isExpired() || !$voucher->isActive()) {
                    return null;
                }

                return [
                    'id' => $userVoucher->id,
                    'voucher_id' => $voucher->id,
                    'voucher_code' => $userVoucher->voucher_code,
                    'name' => $voucher->name,
                    'description' => $voucher->description,
                    'percent' => $voucher->percent,
                    'image' => $voucher->image ? url('/api/storage/' . ltrim($voucher->image, '/')) : null,
                    'expires_at' => $userVoucher->expires_at,
                    'sent_at' => $userVoucher->sent_at,
                    'viewed_at' => $userVoucher->viewed_at,
                    'is_new' => is_null($userVoucher->viewed_at), // Mark if it's a new/unread voucher
                    'is_used' => $userVoucher->isUsed(), // Always false here since we filter, but included for clarity
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'has_available' => $availableVouchers->isNotEmpty(),
            'vouchers' => $availableVouchers,
            'count' => $availableVouchers->count(),
            'new_count' => $availableVouchers->where('is_new', true)->count(),
        ]);
    }

    // Mark voucher as viewed (when modal is shown)
    public function markAsViewed(Request $request, $id)
    {
        $user = $request->user();
        
        $userVoucher = UserVoucher::where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $userVoucher->markAsViewed();

        return response()->json([
            'message' => 'Voucher marked as viewed',
            'voucher_id' => $userVoucher->id,
        ]);
    }

    // Mark all vouchers as viewed (optional - if showing multiple vouchers)
    public function markAllAsViewed(Request $request)
    {
        $user = $request->user();
        
        $updated = UserVoucher::where('user_id', $user->id)
            ->whereNull('viewed_at')
            ->whereNull('used_at')
            ->update(['viewed_at' => now()]);

        return response()->json([
            'message' => 'All vouchers marked as viewed',
            'count' => $updated,
        ]);
    }
}