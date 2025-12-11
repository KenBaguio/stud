<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Notification;
use App\Events\NotificationSent;

class NotificationController extends Controller
{
    /**
     * Display a listing of notifications for the authenticated user.
     * Includes customer information for message notifications.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Optimized: Limit notifications and select only needed columns
        $limit = $request->get('limit', 5); // Default 5, max 100 (can be overridden by frontend)
        $limit = min((int) $limit, 100);

        $notifications = Notification::select('id', 'sender_id', 'receiver_id', 'type', 'title', 'body', 'data', 'is_read', 'created_at', 'updated_at')
            ->where('receiver_id', $user->id)
            ->with(['sender' => function($query) {
                $query->select('id', 'first_name', 'last_name', 'organization_name', 'is_organization');
            }])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        // Transform notifications to include customer information at top level
        $transformedNotifications = $notifications->map(function ($notification) {
            $data = $notification->data ?? [];
            
            // Convert to array to ensure all fields are included
            $notificationArray = $notification->toArray();
            
            // Ensure type is always set (handle null/empty cases)
            if (empty($notificationArray['type'])) {
                $notificationArray['type'] = 'system';
            }
            
            // Log notification type for debugging
            \Log::info("NotificationController: Processing notification", [
                'id' => $notification->id,
                'type' => $notification->type,
                'type_in_array' => $notificationArray['type'],
                'title' => $notification->title,
                'receiver_id' => $notification->receiver_id,
                'has_message_data' => !empty($data['message_id']) || !empty($data['conversation_id'])
            ]);
            
            // For message notifications, extract customer information
            // Use loose comparison to handle null/empty types
            $notificationType = $notificationArray['type'] ?? $notification->type ?? 'system';
            if ($notificationType === 'message' || $notificationType === 'customization') {
                // Get customer info from data field or sender relationship
                $customerId = $data['customer_id'] ?? $data['sender_id'] ?? $notification->sender_id;
                $customerName = $data['customer_name'] ?? $data['sender_name'] ?? null;
                
                // If we have sender relationship and no customer name, get it from sender
                if (!$customerName && $notification->sender) {
                    $customerName = $notification->sender->display_name 
                        ?? trim(($notification->sender->first_name ?? '') . ' ' . ($notification->sender->last_name ?? ''))
                        ?: 'Customer';
                }
                
                // Add customer info to notification at top level for easy access
                $notificationArray['customer_id'] = $customerId;
                $notificationArray['customer_name'] = $customerName;
                $notificationArray['sender_id'] = $customerId; // For compatibility
                $notificationArray['sender_name'] = $customerName; // For compatibility
            }
            
            return $notificationArray;
        });

        // Log summary for debugging
        $typeCounts = [];
        foreach ($transformedNotifications as $notif) {
            $type = $notif['type'] ?? 'unknown';
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
        }
        \Log::info("NotificationController: Returning notifications", [
            'total' => count($transformedNotifications),
            'type_counts' => $typeCounts,
            'user_id' => $user->id
        ]);
        
        return response()->json([
            'success' => true,
            'data' => $transformedNotifications
        ]);
    }

    /**
     * Store a newly created notification and broadcast it.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'title'       => 'required|string|max:255',
            'body'        => 'required|string',
            'data'        => 'nullable|array',
        ]);

        $notification = Notification::create([
            'sender_id'   => $user->id,
            'receiver_id' => $validated['receiver_id'],
            'type'        => $validated['type'] ?? 'system',
            'title'       => $validated['title'],
            'body'        => $validated['body'],
            'data'        => $validated['data'] ?? null,
            'is_read'     => false,
        ]);

        // Fire broadcast event
        // Note: We don't use ->toOthers() here to ensure the receiver always gets the notification
        broadcast(new NotificationSent($notification));

        return response()->json([
            'success' => true,
            'message' => 'Notification sent successfully.',
            'data'    => $notification
        ], 201);
    }

    /**
     * Mark the specified notification as read.
     */
    public function markAsRead($id, Request $request)
    {
        $user = $request->user();

        $notification = Notification::where('id', $id)
            ->where('receiver_id', $user->id)
            ->firstOrFail();

        $notification->update(['is_read' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.',
            'data'    => $notification
        ]);
    }

    /**
     * Mark all notifications as read for the authenticated user.
     */
    public function markAllAsRead(Request $request)
    {
        $user = $request->user();

        Notification::where('receiver_id', $user->id)
            ->update(['is_read' => true]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read.'
        ]);
    }

    /**
     * Return unread notification count for the authenticated user.
     */
    public function unreadCount(Request $request)
    {
        $user = $request->user();

        $count = Notification::where('receiver_id', $user->id)
            ->where('is_read', false)
            ->count();

        return response()->json([
            'success' => true,
            'unread_count' => $count,
        ]);
    }
    /**
     * Mark all notifications from a specific sender (or related to a customer) as read.
     */
    public function markAllFromSender($senderId, Request $request)
    {
        $user = $request->user();

        // Find notifications from this sender (or containing this customer_id in data)
        // This is a bit complex because sender_id might be the customer, OR data['customer_id'] might be
        $updated = Notification::where('receiver_id', $user->id)
            ->where('is_read', false)
            ->where(function ($query) use ($senderId) {
                $query->where('sender_id', $senderId)
                      ->orWhere('data->customer_id', $senderId)
                      ->orWhere('data->sender_id', $senderId);
            })
            ->update(['is_read' => true]);

        if ($updated > 0) {
            $this->broadcastUnreadCount($user->id);
        }

        return response()->json([
            'success' => true,
            'message' => "Marked $updated notifications as read.",
            'count' => $updated
        ]);
    }

    /**
     * Helper to broadcast the new unread count
     */
    protected function broadcastUnreadCount($userId)
    {
        $count = Notification::where('receiver_id', $userId)
            ->where('is_read', false)
            ->count();
            
        broadcast(new \App\Events\NotificationRead($userId, $count));
    }
}
