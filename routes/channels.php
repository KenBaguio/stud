<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\Conversation;

/**
 * Conversation channel - for real-time messaging between customer and clerk
 * - Customer/VIP can access their own conversation
 * - ALL clerks can access (customer service - shared inbox)
 * This ensures both customer and clerk receive messages in real-time
 */
Broadcast::channel('chat.conversation.{conversationId}', function ($user, $conversationId) {
    $conversation = Conversation::find($conversationId);
    if (!$conversation) {
        return false;
    }

    // Customer/VIP can access their own conversation
    // ALL clerks can access (customer service model)
    return $user->role === 'clerk' || (int) $user->id === (int) $conversation->user_id;
});

/**
 * User channel - for direct real-time message delivery
 * - Customer/VIP can access their own channel (receives clerk messages)
 * - ALL clerks can access customer channels (customer service)
 * - Clerks can access their own channel (receives customer messages)
 * This ensures real-time delivery to both customer and clerk
 */
Broadcast::channel('chat.{customerId}', function ($user, $customerId) {
    // Customer/VIP can access their own channel
    // ALL clerks can access customer channels (customer service - shared inbox)
    return (int) $user->id === (int) $customerId || $user->role === 'clerk';
});

// Shared channel for clerks (kung gusto nimo nga tanan clerk makakita sa tanan customer chats)
Broadcast::channel('clerks.shared', function ($user) {
    return $user->role === 'clerk';
});

// Optional: Clerk private channel (kung gusto nimo i-target specific clerk)
// Allow both customer and vip roles to access clerk channels
Broadcast::channel('clerk.{clerkId}', function ($user, $clerkId) {
    return (int) $user->id === (int) $clerkId || in_array($user->role, ['customer', 'vip']);
});
Broadcast::channel('notifications.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Cart private channel - user can only access their own cart channel
Broadcast::channel('cart.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Wishlist private channel - user can only access their own wishlist channel
Broadcast::channel('wishlist.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Vouchers private channel - user can only access their own vouchers channel
Broadcast::channel('vouchers.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
