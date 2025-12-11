<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use App\Models\Message;
use App\Models\Review;
use App\Events\NotificationSent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NotificationService
{
    /**
     * Send a notification to a user
     * 
     * @param int|null $senderId Sender user ID (null for system notifications)
     * @param int $receiverId Receiver user ID
     * @param string $type Notification type (order, proposal, message, walkin, system)
     * @param string $title Notification title
     * @param string $body Notification body/message
     * @param array|null $data Additional data to store with notification
     * @return Notification|null
     */
    public static function send(
        ?int $senderId,
        int $receiverId,
        string $type,
        string $title,
        string $body,
        ?array $data = null
    ): ?Notification {
        try {
            $notificationData = [
                'sender_id' => $senderId,
                'receiver_id' => $receiverId,
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'is_read' => false,
            ];
            
            // Add type field (migration should have been run)
            $notificationData['type'] = $type;
            
            $notification = Notification::create($notificationData);
            
            // Verify the type was saved correctly
            $notification->refresh(); // Reload from database to ensure we have the actual saved values
            if ($notification->type !== $type) {
                Log::warning("NotificationService: Type mismatch after creation", [
                    'expected_type' => $type,
                    'actual_type' => $notification->type,
                    'notification_id' => $notification->id
                ]);
                // Force update the type if it's wrong
                $notification->update(['type' => $type]);
                $notification->refresh();
            }

            // Broadcast the notification to the receiver
            // Note: We don't use ->toOthers() here because we want to ensure
            // the receiver (clerk) always receives the notification, regardless of who triggered it
            try {
                broadcast(new NotificationSent($notification));
                Log::info("Notification sent and broadcasted: {$type} to user {$receiverId} - {$title}", [
                    'notification_id' => $notification->id,
                    'broadcast_driver' => config('broadcasting.default'),
                    'receiver_id' => $receiverId
                ]);
            } catch (\Exception $broadcastError) {
                // Log broadcast error but don't fail notification creation
                Log::warning("Notification created but broadcast failed: " . $broadcastError->getMessage(), [
                    'notification_id' => $notification->id,
                    'broadcast_driver' => config('broadcasting.default'),
                    'error' => $broadcastError->getMessage()
                ]);
                // Still return the notification even if broadcast failed
            }

            return $notification;
        } catch (\Exception $e) {
            Log::error("Failed to send notification: " . $e->getMessage());
            Log::error("Notification data: " . json_encode([
                'sender_id' => $senderId,
                'receiver_id' => $receiverId,
                'type' => $type,
                'title' => $title,
            ]));
            Log::error("Stack trace: " . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Send notification to all admins and clerks
     * 
     * This method ensures ALL active admin and clerk accounts receive notifications,
     * supporting a customer service inbox model where any staff member can respond.
     * 
     * @param int|null $senderId Sender user ID
     * @param string $type Notification type
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array|null $data Additional data
     * @return int Number of notifications sent
     */
    public static function sendToAdminsAndClerks(
        ?int $senderId,
        string $type,
        string $title,
        string $body,
        ?array $data = null
    ): int {
        // Query ALL admins and clerks - no filtering by assignment or status
        // This ensures every staff member receives notifications for customer messages
        $adminsAndClerks = User::whereIn('role', ['admin', 'clerk'])->get();
        
        $count = 0;
        $recipientIds = [];

        foreach ($adminsAndClerks as $user) {
            $notification = self::send($senderId, $user->id, $type, $title, $body, $data);
            if ($notification) {
                $count++;
                $recipientIds[] = $user->id;
            }
        }

        // Log detailed information about notification delivery
        Log::info("NotificationService: Broadcasted '{$type}' notification to {$count} staff members", [
            'recipient_count' => $count,
            'recipient_ids' => $recipientIds,
            'title' => $title
        ]);

        return $count;
    }

    /**
     * Send notification to all clerks only (not admins)
     * 
     * This method ensures ALL active clerk accounts receive notifications,
     * supporting a customer service inbox model where any clerk can respond.
     * 
     * @param int|null $senderId Sender user ID
     * @param string $type Notification type
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array|null $data Additional data
     * @return int Number of notifications sent
     */
    public static function sendToClerksOnly(
        ?int $senderId,
        string $type,
        string $title,
        string $body,
        ?array $data = null
    ): int {
        // Query ALL clerks only - no filtering by assignment or status
        // This ensures every clerk receives notifications for customer messages
        $clerks = User::where('role', 'clerk')->get();
        
        Log::info("NotificationService: sendToClerksOnly called", [
            'type' => $type,
            'title' => $title,
            'clerk_count' => $clerks->count(),
            'clerk_ids' => $clerks->pluck('id')->toArray()
        ]);
        
        $count = 0;
        $recipientIds = [];

        foreach ($clerks as $clerk) {
            $notification = self::send($senderId, $clerk->id, $type, $title, $body, $data);
            if ($notification) {
                $count++;
                $recipientIds[] = $clerk->id;
                Log::info("NotificationService: Notification created for clerk", [
                    'clerk_id' => $clerk->id,
                    'clerk_email' => $clerk->email,
                    'notification_id' => $notification->id,
                    'notification_type' => $notification->type,
                    'expected_type' => $type,
                    'type_match' => $notification->type === $type
                ]);
            } else {
                Log::error("NotificationService: Failed to create notification for clerk", [
                    'clerk_id' => $clerk->id,
                    'clerk_email' => $clerk->email,
                    'type' => $type,
                    'error' => 'Notification::send() returned null'
                ]);
            }
        }

        // Log detailed information about notification delivery
        Log::info("NotificationService: Broadcasted '{$type}' notification to {$count} clerks", [
            'recipient_count' => $count,
            'recipient_ids' => $recipientIds,
            'title' => $title,
            'total_clerks' => $clerks->count()
        ]);

        return $count;
    }

    /**
     * Send order creation notification to admins/clerks
     */
    public static function notifyOrderCreated($order, $customerName): int
    {
        $orderNumber = 'ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT);
        $title = 'New Order Received';
        $body = "{$customerName} placed a new order (#{$orderNumber}) for ₱" . number_format($order->total_amount, 2);
        
        $data = [
            'order_id' => $order->id,
            'order_number' => $orderNumber,
            'total_amount' => $order->total_amount,
            'status' => $order->status,
        ];

        return self::sendToAdminsAndClerks(
            $order->user_id,
            'order',
            $title,
            $body,
            $data
        );
    }

    /**
     * Send order status update notification to customer
     */
    public static function notifyOrderStatusUpdated($order, $oldStatus, $newStatus): ?Notification
    {
        $customer = $order->user;
        if (!$customer) {
            return null;
        }

        $orderNumber = 'ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT);
        $statusLabels = [
            'pending' => 'Pending',
            'confirmed' => 'Confirmed',
            'processing' => 'Processing',
            'packaging' => 'Packaging',
            'on_delivery' => 'On Delivery',
            'delivered' => 'Delivered',
        ];

        $newStatusLabel = $statusLabels[$newStatus] ?? ucfirst($newStatus);
        $title = 'Order Status Updated';
        $body = "Your order #{$orderNumber} status has been updated to: {$newStatusLabel}";

        $data = [
            'order_id' => $order->id,
            'order_number' => $orderNumber,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'status_label' => $newStatusLabel,
        ];

        return self::send(
            null, // System notification
            $customer->id,
            'order',
            $title,
            $body,
            $data
        );
    }

    /**
     * Send custom proposal creation notification to customer
     */
    public static function notifyProposalCreated($proposal, $clerkName): ?Notification
    {
        try {
            $customer = User::find($proposal->customer_id);
            if (!$customer) {
                Log::warning("NotificationService: Customer not found for proposal {$proposal->id}, customer_id: {$proposal->customer_id}");
                return null;
            }

            $title = 'New Custom Proposal';
            $body = "{$clerkName} has created a custom proposal: {$proposal->name}";

            $data = [
                'proposal_id' => $proposal->id,
                'proposal_name' => $proposal->name,
                'category' => $proposal->category,
                'total_price' => $proposal->total_price,
            ];

            $notification = self::send(
                $proposal->user_id,
                $customer->id,
                'proposal',
                $title,
                $body,
                $data
            );

            if ($notification) {
                Log::info("NotificationService: Proposal notification sent to customer {$customer->id} for proposal {$proposal->id}");
            } else {
                Log::error("NotificationService: Failed to send proposal notification to customer {$customer->id} for proposal {$proposal->id}");
            }

            return $notification;
        } catch (\Exception $e) {
            Log::error("NotificationService: Exception sending proposal notification: " . $e->getMessage());
            Log::error("NotificationService: Stack trace: " . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Send voucher notification to customer when voucher is sent
     */
    public static function notifyVoucherSent($userVoucher, $voucher): ?Notification
    {
        try {
            $customer = $userVoucher->user;
            if (!$customer) {
                Log::warning("NotificationService: Customer not found for voucher {$userVoucher->id}");
                return null;
            }

            $title = 'New Voucher Received';
            $body = "You've received a new voucher: {$voucher->name} ({$voucher->percent}% off)";
            
            $expiresAt = $userVoucher->expires_at ? $userVoucher->expires_at->format('Y-m-d H:i:s') : null;
            $data = [
                'voucher_id' => $voucher->id,
                'user_voucher_id' => $userVoucher->id,
                'voucher_code' => $userVoucher->voucher_code,
                'percent' => $voucher->percent,
                'expires_at' => $expiresAt,
            ];

            $notification = self::send(
                null, // System notification
                $customer->id,
                'voucher',
                $title,
                $body,
                $data
            );

            if ($notification) {
                Log::info("NotificationService: Voucher notification sent to customer {$customer->id} for voucher {$voucher->id}");
            }

            return $notification;
        } catch (\Exception $e) {
            Log::error("NotificationService: Exception sending voucher notification: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Notify all users who have a voucher when it is enabled
     */
    public static function notifyVoucherEnabled($voucher): int
    {
        try {
            $voucher->loadMissing('userVouchers.user');
            
            // Get all users who have this voucher and haven't used it yet
            $userVouchers = $voucher->userVouchers()
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->with('user')
                ->get();
            
            if ($userVouchers->isEmpty()) {
                Log::info("NotificationService: No active user vouchers found for voucher {$voucher->id}");
                return 0;
            }
            
            $title = 'Voucher Enabled';
            $body = "Great news! The voucher '{$voucher->name}' ({$voucher->percent}% off) is now enabled and available for use.";
            
            $data = [
                'voucher_id' => $voucher->id,
                'voucher_name' => $voucher->name,
                'percent' => $voucher->percent,
            ];
            
            $count = 0;
            $recipientIds = [];
            
            foreach ($userVouchers as $userVoucher) {
                if (!$userVoucher->user) {
                    continue;
                }
                
                $notification = self::send(
                    null, // System notification
                    $userVoucher->user->id,
                    'voucher',
                    $title,
                    $body,
                    $data
                );
                
                if ($notification) {
                    $count++;
                    $recipientIds[] = $userVoucher->user->id;
                }
            }
            
            Log::info("NotificationService: Voucher enabled notification sent to {$count} users", [
                'voucher_id' => $voucher->id,
                'voucher_name' => $voucher->name,
                'recipient_count' => $count,
                'recipient_ids' => $recipientIds
            ]);
            
            return $count;
        } catch (\Exception $e) {
            Log::error("NotificationService: Exception sending voucher enabled notification: " . $e->getMessage());
            Log::error("NotificationService: Stack trace: " . $e->getTraceAsString());
            return 0;
        }
    }

    /**
     * Notify all users who have a voucher when it is disabled
     */
    public static function notifyVoucherDisabled($voucher): int
    {
        try {
            $voucher->loadMissing('userVouchers.user');
            
            // Get all users who have this voucher and haven't used it yet
            $userVouchers = $voucher->userVouchers()
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->with('user')
                ->get();
            
            if ($userVouchers->isEmpty()) {
                Log::info("NotificationService: No active user vouchers found for voucher {$voucher->id}");
                return 0;
            }
            
            $title = 'Voucher Disabled';
            $body = "The voucher '{$voucher->name}' ({$voucher->percent}% off) has been disabled and is no longer available for use.";
            
            $data = [
                'voucher_id' => $voucher->id,
                'voucher_name' => $voucher->name,
                'percent' => $voucher->percent,
            ];
            
            $count = 0;
            $recipientIds = [];
            
            foreach ($userVouchers as $userVoucher) {
                if (!$userVoucher->user) {
                    continue;
                }
                
                $notification = self::send(
                    null, // System notification
                    $userVoucher->user->id,
                    'voucher',
                    $title,
                    $body,
                    $data
                );
                
                if ($notification) {
                    $count++;
                    $recipientIds[] = $userVoucher->user->id;
                }
            }
            
            Log::info("NotificationService: Voucher disabled notification sent to {$count} users", [
                'voucher_id' => $voucher->id,
                'voucher_name' => $voucher->name,
                'recipient_count' => $count,
                'recipient_ids' => $recipientIds
            ]);
            
            return $count;
        } catch (\Exception $e) {
            Log::error("NotificationService: Exception sending voucher disabled notification: " . $e->getMessage());
            Log::error("NotificationService: Stack trace: " . $e->getTraceAsString());
            return 0;
        }
    }

    /**
     * Send voucher used notification to customer
     */
    public static function notifyVoucherUsed($order, $voucher, $voucherCode, $discountAmount): ?Notification
    {
        try {
            $customer = $order->user;
            if (!$customer) {
                Log::warning("NotificationService: Customer not found for order {$order->id}");
                return null;
            }

            $title = 'Voucher Applied Successfully';
            $body = "Your voucher '{$voucher->name}' has been applied to Order #{$order->id}. You saved ₱" . number_format($discountAmount, 2) . "!";

            $data = [
                'order_id' => $order->id,
                'voucher_id' => $voucher->id,
                'voucher_code' => $voucherCode,
                'discount_amount' => $discountAmount,
            ];

            $notification = self::send(
                null, // System notification
                $customer->id,
                'voucher',
                $title,
                $body,
                $data
            );

            if ($notification) {
                Log::info("NotificationService: Voucher used notification sent to customer {$customer->id} for order {$order->id}");
            }

            return $notification;
        } catch (\Exception $e) {
            Log::error("NotificationService: Exception sending voucher used notification: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Send customization request notification to all clerks
     */
    public static function notifyCustomizationRequest($customer, $message = null, $productData = null): int
    {
        try {
            $adminAndClerkUsers = User::whereIn('role', ['admin', 'clerk'])->get();
            $count = 0;

            $customerName = $customer->display_name ?? $customer->first_name ?? $customer->name ?? 'A customer';
            $productName = $productData['name'] ?? 'a product';
            
            $title = 'New Customization Request';
            $body = $message 
                ? "{$customerName} wants to customize {$productName}: {$message}"
                : "{$customerName} wants to customize {$productName}";

            $data = [
                'customer_id' => $customer->id,
                'customer_name' => $customerName,
                'product_data' => $productData,
                'message' => $message,
            ];

            foreach ($adminAndClerkUsers as $user) {
                $notification = self::send(
                    $customer->id, // Customer is the sender
                    $user->id,
                    'customization',
                    $title,
                    $body,
                    $data
                );

                if ($notification) {
                    $count++;
                }
            }

            Log::info("NotificationService: Customization request notifications sent to {$count} clerks for customer {$customer->id}");

            return $count;
        } catch (\Exception $e) {
            Log::error("NotificationService: Exception sending customization request notifications: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Send chat message notification to a specific recipient
     */
    public static function notifyChatMessage(Message $message, ?User $recipient = null): ?Notification
    {
        $message->loadMissing(['sender', 'receiver']);

        $sender = $message->sender;
        $targetRecipient = $recipient ?? $message->receiver;

        if (!$sender || !$targetRecipient || $sender->id === $targetRecipient->id) {
            return null;
        }

        $senderName = $sender->display_name
            ?? trim(($sender->first_name ?? '') . ' ' . ($sender->last_name ?? ''))
            ?: 'Someone';

        $previewSource = $message->message;
        $hasImages = is_array($message->images) ? count($message->images) > 0 : !empty($message->images);
        if (!$previewSource && $hasImages) {
            $previewSource = 'Sent an attachment';
        }
        $preview = $previewSource ? Str::limit(trim(strip_tags($previewSource)), 120) : 'Sent a message';

        $title = 'New Message';
        $body = "{$senderName}: {$preview}";

        $data = [
            'message_id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender_id' => $sender->id,
            'sender_name' => $senderName,
            'preview' => $preview,
            'has_images' => $hasImages,
        ];

        return self::send(
            $sender->id,
            $targetRecipient->id,
            'message',
            $title,
            $body,
            $data
        );
    }

    /**
     * Notify clerks about a customer chat message
     * 
     * This method broadcasts notifications to ALL active clerk accounts (not admins)
     * whenever a customer sends a message. This supports a customer service inbox
     * model where any available clerk can see and respond to customer messages
     * in real-time, ensuring no messages go unnoticed.
     * 
     * @param Message $message The customer message that triggered the notification
     * @param int|null $preferredClerkId Optional: ID of a preferred/assigned clerk (for reference only, does not limit recipients)
     * @return int Number of clerks who received the notification
     */
    public static function notifyStaffOfCustomerMessage(Message $message, ?int $preferredClerkId = null): int
    {
        $message->loadMissing(['sender']);
        $customer = $message->sender;

        if (!$customer) {
            Log::warning("NotificationService: Customer not found for message {$message->id}");
            return 0;
        }

        $customerName = $customer->display_name
            ?? trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''))
            ?: 'Customer';

        $previewSource = $message->message;
        $hasImages = is_array($message->images) ? count($message->images) > 0 : !empty($message->images);
        if (!$previewSource && $hasImages) {
            $previewSource = 'Sent an attachment';
        }
        $preview = $previewSource ? Str::limit(trim(strip_tags($previewSource)), 120) : 'Sent a message';

        $title = 'New Customer Message';
        $body = "{$customerName}: {$preview}";

        $data = [
            'message_id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'customer_id' => $customer->id,
            'customer_name' => $customerName,
            'sender_id' => $customer->id, // Include sender_id for compatibility
            'sender_name' => $customerName, // Include sender_name for compatibility
            'preview' => $preview,
            'has_images' => $hasImages,
            'assigned_clerk_id' => $preferredClerkId, // Included for reference, but does not limit recipients
            // Include message content for better notification display
            'message' => $message->message,
            'images' => $hasImages ? (
                is_array($message->images) 
                    ? $message->images 
                    : (is_string($message->images) ? json_decode($message->images, true) : [])
            ) : null,
        ];

        // CRITICAL: Broadcast to ALL clerks only (not admins), regardless of assignment
        // This ensures every clerk receives real-time notifications when any customer sends a message
        // The platform functions as a shared customer service inbox where any clerk can respond
        $count = self::sendToClerksOnly(
            $customer->id,
            'message',
            $title,
            $body,
            $data
        );

        Log::info("NotificationService: Broadcasted customer message notification to {$count} clerks", [
            'customer_id' => $customer->id,
            'customer_name' => $customerName,
            'message_id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'preferred_clerk_id' => $preferredClerkId,
            'notification_count' => $count
        ]);

        return $count;
    }

    /**
     * Notify admins when a customer submits a review
     */
    public static function notifyReviewSubmitted(Review $review): int
    {
        try {
            $review->loadMissing('user');
            $customer = $review->user;

            $customerName = $customer
                ? ($customer->display_name
                    ?? trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''))
                    ?: 'Customer')
                : 'Customer';

            $title = 'New Customer Review';
            $body = "{$customerName} left a {$review->rating}-star review.";

            $data = [
                'review_id' => $review->id,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'customer_id' => $customer->id ?? null,
                'customer_name' => $customerName,
            ];

            $admins = User::where('role', 'admin')->get();
            $count = 0;

            foreach ($admins as $admin) {
                $notification = self::send(
                    $customer->id ?? null,
                    $admin->id,
                    'review',
                    $title,
                    $body,
                    $data
                );

                if ($notification) {
                    $count++;
                }
            }

            return $count;
        } catch (\Exception $e) {
            Log::error("NotificationService: Exception sending review notification: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Send walk-in purchase notification to admins/clerks
     */
    public static function notifyWalkinPurchaseCreated($walkinPurchase): int
    {
        $title = 'New Walk-In Purchase';
        $body = "Walk-in purchase from {$walkinPurchase->customer_name}: {$walkinPurchase->product_name} - ₱" . number_format($walkinPurchase->total_price, 2);

        $data = [
            'purchase_id' => $walkinPurchase->id,
            'customer_name' => $walkinPurchase->customer_name,
            'product_name' => $walkinPurchase->product_name,
            'total_price' => $walkinPurchase->total_price,
            'category' => $walkinPurchase->category,
        ];

        return self::sendToAdminsAndClerks(
            null, // System notification
            'walkin',
            $title,
            $body,
            $data
        );
    }

    /**
     * Send system notification
     */
    public static function notifySystem(int $receiverId, string $title, string $body, ?array $data = null): ?Notification
    {
        return self::send(
            null,
            $receiverId,
            'system',
            $title,
            $body,
            $data
        );
    }

    /**
     * Notify a customer when they are promoted to VIP
     */
    public static function notifyVipPromotion(User $customer): ?Notification
    {
        $customerName = $customer->display_name
            ?? trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''))
            ?: $customer->email
            ?: 'Valued Customer';

        $title = 'You are now a VIP!';
        $body = "Congratulations {$customerName}, your account has been upgraded to VIP status. Enjoy exclusive perks and rewards.";

        $data = [
            'customer_id' => $customer->id,
            'role' => 'vip',
            'type' => 'vip',
            'promoted_at' => now()->toDateTimeString(),
        ];

        return self::send(
            null,
            $customer->id,
            'vip',
            $title,
            $body,
            $data
        );
    }
}

