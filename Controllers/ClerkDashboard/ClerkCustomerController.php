<?php

namespace App\Http\Controllers\ClerkDashboard;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClerkCustomerController extends Controller
{
    /**
     * Get current logged-in clerk info
     */
    public function me(Request $request)
    {
        // Assumes JWT auth: $request->user() returns authenticated clerk
        $clerk = $request->user();

        if (!$clerk) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        return response()->json([
            'id' => $clerk->id,
            'name' => $clerk->name,
            'email' => $clerk->email,
            'role' => $clerk->role,
            'profile_picture' => $clerk->profile_image
                ? url('storage/' . $clerk->profile_image)
                : null,
        ]);
    }

    /**
     * Fetch all customers relevant to clerks (Customers, VIPs, Organizations)
     * 
     * IMPORTANT: Clerk can see ALL customers/VIPs (like customer service)
     * Multiple clerks can exist, but each customer/VIP has one conversation
     * Clerk can view messages from any customer by selecting them
     */
    public function index(Request $request)
    {
        try {
            $query = Customer::query()->whereIn('role', ['customer', 'vip', 'organization']);

            // Optional search filter
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%$search%")
                      ->orWhere('last_name', 'like', "%$search%")
                      ->orWhere('organization_name', 'like', "%$search%")
                      ->orWhere('email', 'like', "%$search%");
                });
            }

            // Add query limit support (default 5, max 100)
            $limit = $request->query('limit', 5);
            $limit = min((int) $limit, 100);
            
            $rawCustomers = $query->limit($limit)->get();
            $customerIds = $rawCustomers->pluck('id');
            $conversationMap = Conversation::whereIn('user_id', $customerIds)
                ->limit($limit)
                ->get()
                ->keyBy('user_id');

            $ordersSummary = Order::select(
                    'user_id',
                    DB::raw('COUNT(*) as total_orders'),
                    DB::raw('COALESCE(SUM(total_amount), 0) as total_spent')
                )
                ->whereIn('user_id', $customerIds)
                ->groupBy('user_id')
                ->get()
                ->keyBy('user_id');

            $conversationIds = $conversationMap->pluck('id')->filter()->values();
            $messagesSummary = $conversationIds->isNotEmpty()
                ? Message::select(
                        'conversation_id',
                        DB::raw('COUNT(*) as total_messages')
                    )
                    ->whereIn('conversation_id', $conversationIds)
                    ->groupBy('conversation_id')
                    ->get()
                    ->keyBy('conversation_id')
                : collect();

            $lastCustomerActivity = Message::select('sender_id', DB::raw('MAX(created_at) as last_msg_at'))
                ->whereIn('sender_id', $customerIds)
                ->groupBy('sender_id')
                ->get()
                ->keyBy('sender_id');

            $customers = $rawCustomers->map(function ($c) use ($conversationMap, $ordersSummary, $messagesSummary, $lastCustomerActivity) {
                $displayName = $c->is_organization
                    ? ($c->organization_name ?? 'Unnamed Organization')
                    : trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''));

                $conversation = $conversationMap->get($c->id);
                $orderStats = $ordersSummary->get($c->id);
                $messageStats = $conversation
                    ? $messagesSummary->get($conversation->id)
                    : null;
                
                // Fix: Calculate last activity based on USER's actions only
                // (e.g. user sent a message or updated profile), 
                // ignoring messages sent BY the clerk TO the user.
                $userMsgStats = $lastCustomerActivity->get($c->id);
                $lastUserMsgTime = $userMsgStats ? $userMsgStats->last_msg_at : null;

                $lastActivity = $lastUserMsgTime 
                    ?? $c->updated_at 
                    ?? $c->created_at;

                $lastActiveIso = $lastActivity
                    ? Carbon::parse($lastActivity)->toIso8601String()
                    : null;

                $isOnline = $lastActivity
                    ? Carbon::parse($lastActivity)->greaterThan(now()->subMinutes(5))
                    : false;

                return [
                    'id' => $c->id,
                    'display_name' => $displayName,
                    'email' => $c->email ?? '',
                    'role' => $c->role ?? 'customer',
                    'is_organization' => (bool) $c->is_organization,
                    'organization_name' => $c->organization_name,
                    'first_name' => $c->first_name,
                    'last_name' => $c->last_name,
                    'phone' => $c->phone,
                    'address' => $c->address,
                    'dob' => $c->dob,
                    'date_founded' => $c->date_founded,
                    'business_type' => $c->business_type,
                    'industry' => $c->industry,
                    'created_at' => $c->created_at,
                    'total_orders' => optional($orderStats)->total_orders ?? 0,
                    'total_spent' => $orderStats
                        ? (float) $orderStats->total_spent
                        : 0,
                    'message_count' => optional($messageStats)->total_messages ?? 0,
                    'profile_picture' => $c->profile_image
                        ? url('storage/' . $c->profile_image)
                        : null,
                    'profile_image' => $c->profile_image, // Raw path for frontend processing
                    'conversation_id' => $conversation->id ?? null,
                    'last_active' => $lastActiveIso,
                    'is_online' => $isOnline,
                ];
            });

            return response()->json($customers);

        } catch (\Exception $e) {
            \Log::error('ClerkCustomerController@index error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to fetch customers: ' . $e->getMessage()
            ], 500);
        }
    }
}
