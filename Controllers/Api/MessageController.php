<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Events\MessageSent;
use App\Events\TypingStarted;
use App\Events\TypingStopped;
use App\Services\NotificationService;

class MessageController extends Controller
{

    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);

        $conversation = Conversation::firstOrCreate(
            ['user_id' => $user->id],
            ['created_at' => now(), 'updated_at' => now()]
        );

        // Fetch ALL messages from the conversation (both from customer/VIP and ALL clerks)
        // IMPORTANT: This endpoint MUST fetch messages from clerks (customer service)
        // - No filtering by sender_id, receiver_id, or active_clerk_id
        // - Customers/VIPs can see messages from ANY clerk, not just the active one
        // - The conversation is based on user_id only, so ALL clerks' messages to this customer/VIP
        //   are stored in the SAME conversation and will ALL be returned here
        // 
        // MySQL Query: SELECT * FROM messages WHERE conversation_id = ? ORDER BY created_at DESC
        // This correctly uses conversation_id from the messages table
        $messagesQuery = $conversation->messages()
            ->with(['sender', 'receiver'])
            // NO WHERE clauses filtering by sender_id, receiver_id, or clerk role
            // This ensures messages from ALL clerks (customer service) are included
            ->orderBy('created_at', 'desc'); // Changed to desc to get latest messages first
        
        // Log for debugging - show messages from all clerks
        // Direct query to verify messages exist in database
        $directCount = \DB::table('messages')
            ->where('conversation_id', $conversation->id)
            ->count();
        
        $allMessages = $conversation->messages()->with('sender')->get();
        $clerkIds = $allMessages->where('sender.role', 'clerk')->pluck('sender_id')->unique()->values()->toArray();
        
        \Log::info('Fetching messages for conversation', [
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'user_role' => $user->role,
            'active_clerk_id' => $conversation->active_clerk_id,
            'total_messages_via_relationship' => $conversation->messages()->count(),
            'total_messages_via_direct_query' => $directCount,
            'unique_clerk_ids_in_messages' => $clerkIds,
            'total_clerks_in_conversation' => count($clerkIds),
            'message_ids' => $allMessages->pluck('id')->toArray()
        ]);

        $beforeId = $request->query('before_id');
        if ($beforeId) {
            $messagesQuery->where('id', '<', $beforeId);
        }

        // Only apply limit if explicitly requested (for pagination)
        // Otherwise, fetch all messages to display older messages properly
        $limit = $request->query('limit');
        if ($limit !== null) {
            $limit = min((int) $limit, 1000); // Max 1000 if limit is specified
            $messagesQuery->limit($limit);
        }
        // If no limit specified, fetch all messages

        // Execute query and get messages from database
        // This queries: SELECT * FROM messages WHERE conversation_id = ? ORDER BY created_at DESC
        $messages = $messagesQuery->get()->reverse(); // Reverse to maintain chronological order
        
        // Verify we got messages from database
        if ($messages->isEmpty()) {
            \Log::warning('No messages found in database for conversation', [
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'query_executed' => 'SELECT * FROM messages WHERE conversation_id = ' . $conversation->id . ' ORDER BY created_at DESC'
            ]);
        }
        
        // Log message breakdown - including breakdown by clerk
        $clerkMessages = $messages->where('sender.role', 'clerk');
        $clerkBreakdown = $clerkMessages->groupBy('sender_id')->map(function($group, $clerkId) {
            return [
                'clerk_id' => $clerkId,
                'clerk_name' => $group->first()->sender->first_name . ' ' . $group->first()->sender->last_name,
                'message_count' => $group->count()
            ];
        })->values()->toArray();
        
        // Log sample message data to verify database structure
        $sampleMessage = $messages->first();
        \Log::info('Messages fetched from database', [
            'conversation_id' => $conversation->id,
            'limit_requested' => $request->query('limit', 50),
            'limit_applied' => $limit,
            'messages_count' => $messages->count(),
            'customer_messages' => $messages->where('sender.role', 'customer')->count(),
            'clerk_messages' => $messages->where('sender.role', 'clerk')->count(),
            'vip_messages' => $messages->where('sender.role', 'vip')->count(),
            'clerk_breakdown' => $clerkBreakdown, // Shows messages from each clerk
            'total_unique_clerks' => count($clerkBreakdown),
            'sample_message_structure' => $sampleMessage ? [
                'id' => $sampleMessage->id,
                'conversation_id' => $sampleMessage->conversation_id,
                'sender_id' => $sampleMessage->sender_id,
                'receiver_id' => $sampleMessage->receiver_id,
                'has_message' => !empty($sampleMessage->message),
                'has_product' => !empty($sampleMessage->product),
                'has_images' => !empty($sampleMessage->images),
                'created_at' => $sampleMessage->created_at
            ] : null
        ]);

        // Parse message data for each message and ensure sender/receiver info is included
        $messages->transform(function($message) {
            $parsed = $this->parseMessageData($message);
            
            // Ensure sender information is included
            if ($message->sender) {
                $parsed->sender = [
                    'id' => $message->sender->id,
                    'first_name' => $message->sender->first_name,
                    'last_name' => $message->sender->last_name,
                    'email' => $message->sender->email,
                    'role' => $message->sender->role,
                    'profile_image' => $message->sender->profile_image,
                ];
            }
            
            // Ensure receiver information is included
            if ($message->receiver) {
                $parsed->receiver = [
                    'id' => $message->receiver->id,
                    'first_name' => $message->receiver->first_name,
                    'last_name' => $message->receiver->last_name,
                    'email' => $message->receiver->email,
                    'role' => $message->receiver->role,
                ];
            }
            
            return $parsed;
        });

        $conversation->load('activeClerk');

        // Final log - confirm all messages from all clerks are included
        $finalClerkCount = $messages->where('sender.role', 'clerk')->pluck('sender_id')->unique()->count();
        \Log::info('Messages response prepared', [
            'conversation_id' => $conversation->id,
            'message_count' => $messages->count(),
            'customer_messages' => $messages->where('sender.role', 'customer')->count(),
            'clerk_messages' => $messages->where('sender.role', 'clerk')->count(),
            'unique_clerks_in_response' => $finalClerkCount,
            'note' => 'All messages from all clerks are included (not filtered by active_clerk_id)'
        ]);

        return response()->json([
            'conversation' => $conversation,
            'messages' => $messages->values()->toArray(), // Ensure array format for frontend compatibility
        ]);
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);

        $validated = $request->validate([
            'conversation_id' => 'nullable|exists:conversations,id',
            'receiver_id' => 'nullable|exists:users,id',
            'message' => 'nullable|string',
            'product_data' => 'nullable|array',
            'is_quick_option' => 'nullable|boolean',
            'images' => 'nullable|array',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:10240',
        ]);

        // IMPORTANT: Conversation is based on user_id (customer/VIP) only
        // This means ALL clerks' messages to this customer/VIP are stored in the SAME conversation
        // When any clerk sends a message, they use Conversation::firstOrCreate(['user_id' => customer_id])
        // So all messages from all clerks appear in the customer/VIP's dashboard messages
        $conversation = null;
        if (!empty($validated['conversation_id'])) {
            $conversation = Conversation::where('id', $validated['conversation_id'])
                ->where('user_id', $user->id)
                ->first();

            if (!$conversation) {
                return response()->json(['message' => 'Conversation not found'], 404);
            }
        } else {
            // Create or get the conversation for this customer/VIP
            // All clerks' messages to this user will be in this same conversation
            $conversation = Conversation::firstOrCreate(
                ['user_id' => $user->id],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        // Handle image uploads - use /api/storage/ endpoint for images
        $uploadedImages = [];
        if ($request->hasFile('images')) {
            $disk = \App\Helpers\R2Helper::getStorageDisk();
            foreach ($request->file('images') as $image) {
                try {
                    $path = $image->store('chat-images', $disk);
                    // Use /api/storage/ endpoint for images (handles R2 URLs properly)
                    $uploadedImages[] = url('/api/storage/' . ltrim($path, '/'));
                } catch (\Exception $e) {
                    \Log::error('Failed to upload message image: ' . $e->getMessage());
                    // Continue with other images
                }
            }
        }

        // Prepare product data for storage
        $productData = null;
        if ($request->has('product_data') && $request->product_data) {
            $productData = [
                'id' => $request->product_data['id'] ?? null,
                'name' => $request->product_data['name'] ?? null,
                'price' => $request->product_data['price'] ?? null,
                'material' => $request->product_data['material'] ?? null,
                'description' => $request->product_data['description'] ?? null,
                'images' => $request->product_data['images'] ?? [],
                'current_image_index' => $request->product_data['current_image_index'] ?? 0,
                'timestamp' => $request->product_data['timestamp'] ?? now()->toISOString()
            ];
        }

        // Prepare message data
        // IMPORTANT: For real-time messaging between customer and clerk (customer service)
        // - When customer/VIP sends: receiver_id = active_clerk_id (so clerk receives in real-time)
        // - When clerk sends: receiver_id = customer/VIP id (so customer receives in real-time)
        // - This ensures both sides receive messages via their user channels (chat.{userId})
        $receiverId = null;
        if (!empty($validated['receiver_id'])) {
            // Explicit receiver_id provided (e.g., from frontend)
            $receiverId = $validated['receiver_id'];
        } else if ($conversation->active_clerk_id && in_array($user->role, ['customer', 'vip'])) {
            // Customer/VIP sending to active clerk
            $receiverId = $conversation->active_clerk_id;
        } else if (in_array($user->role, ['customer', 'vip'])) {
            // Customer/VIP but no active clerk - find first available clerk
            $firstClerk = \App\Models\User::where('role', 'clerk')->first();
            $receiverId = $firstClerk ? $firstClerk->id : $conversation->user_id;
        } else {
            // Clerk sending to customer/VIP
            $receiverId = $conversation->user_id;
        }
        
        $messageData = [
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'receiver_id' => $receiverId, // Set correctly for real-time delivery
            'message' => $request->message ?? null,
            'product' => $productData ? json_encode($productData) : null,
            'is_quick_option' => $request->is_quick_option ?? false,
        ];
        
        \Log::info('Message data prepared for real-time delivery', [
            'sender_id' => $messageData['sender_id'],
            'sender_role' => $user->role,
            'receiver_id' => $messageData['receiver_id'],
            'conversation_id' => $messageData['conversation_id'],
            'will_broadcast_to' => [
                'conversation_channel' => 'chat.conversation.' . $messageData['conversation_id'],
                'receiver_channel' => 'chat.' . $messageData['receiver_id'],
                'sender_channel' => 'chat.' . $messageData['sender_id']
            ]
        ]);

        // Add images to message if any were uploaded
        if (!empty($uploadedImages)) {
            $messageData['images'] = json_encode($uploadedImages);
        }

        // Check if this is a new conversation (before creating the message)
        $messageCountBefore = Message::where('conversation_id', $conversation->id)->count();
        $isNewConversation = $messageCountBefore === 0;
        // FIXED: Check for 'customer' or 'vip' role - both are customers who should trigger notifications
        // Exclude 'clerk' and 'admin' roles as they are staff members
        $isCustomerMessage = in_array($user->role, ['customer', 'vip']);
        
        // Log user role and message type for debugging
        \Log::info("MessageController: Message creation", [
            'user_id' => $user->id,
            'user_role' => $user->role,
            'is_customer_message' => $isCustomerMessage,
            'is_new_conversation' => $isNewConversation,
            'conversation_id' => $conversation->id
        ]);

        $message = Message::create($messageData);

        // Load the message with relationships
        $message->load(['sender', 'receiver']);
        
        // Prepare message data for response and broadcasting
        $messageData = $this->parseMessageData($message);

        $conversation->forceFill([
            'last_message_at' => now(),
            'updated_at' => now(),
        ]);

        // Update active_clerk_id if receiver is a clerk (for customer service)
        // This ensures the conversation is associated with the clerk handling it
        if (!empty($validated['receiver_id'])) {
            $receiver = \App\Models\User::find($validated['receiver_id']);
            if ($receiver && $receiver->role === 'clerk') {
                $conversation->active_clerk_id = $validated['receiver_id'];
            }
        } else if ($receiverId) {
            // Check if receiver is a clerk and update active_clerk_id
            $receiver = \App\Models\User::find($receiverId);
            if ($receiver && $receiver->role === 'clerk') {
                $conversation->active_clerk_id = $receiverId;
            }
        }

        $conversation->save();

        if ($isCustomerMessage) {
            // For new conversations, send customization request notification
            if ($isNewConversation) {
                NotificationService::notifyCustomizationRequest($user, $request->message, $productData);
            }

            // ALWAYS notify clerks about incoming customer message (for both new and existing conversations)
            // This ensures clerks receive notifications for every customer message
            $notificationCount = NotificationService::notifyStaffOfCustomerMessage($message, $conversation->active_clerk_id);
            
            // Log notification creation for debugging
            \Log::info("MessageController: Customer message notification sent", [
                'message_id' => $message->id,
                'customer_id' => $user->id,
                'conversation_id' => $conversation->id,
                'notification_count' => $notificationCount,
                'is_new_conversation' => $isNewConversation
            ]);
        }

        // Broadcast the event
        broadcast(new MessageSent($message));

        return response()->json($messageData, 201);
    }

    /**
     * Parse message data for consistent response format
     */
    private function parseMessageData($message)
    {
        // Parse product data
        if ($message->product && is_string($message->product)) {
            try {
                $message->product = json_decode($message->product, true);
            } catch (\Exception $e) {
                $message->product = null;
            }
        }

        // Parse images data
        if ($message->images && is_string($message->images)) {
            try {
                $message->images = json_decode($message->images, true);
            } catch (\Exception $e) {
                $message->images = [];
            }
        }

        $message->isProductReference = !!$message->product;
        $message->hasImages = !empty($message->images);

        return $message;
    }

    /**
     * Start typing indicator
     */
    public function startTyping(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'conversation_id' => 'required|exists:conversations,id',
        ]);

        $conversation = Conversation::find($validated['conversation_id']);
        if (!$conversation) {
            return response()->json(['message' => 'Conversation not found'], 404);
        }

        // Verify user has access to this conversation
        if ($user->role !== 'clerk' && (int) $conversation->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Broadcast typing started event
        broadcast(new TypingStarted($user->id, $conversation->id, $user));

        return response()->json(['success' => true]);
    }

    /**
     * Stop typing indicator
     */
    public function stopTyping(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'conversation_id' => 'required|exists:conversations,id',
        ]);

        $conversation = Conversation::find($validated['conversation_id']);
        if (!$conversation) {
            return response()->json(['message' => 'Conversation not found'], 404);
        }

        // Verify user has access to this conversation
        if ($user->role !== 'clerk' && (int) $conversation->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Broadcast typing stopped event
        broadcast(new TypingStopped($user->id, $conversation->id));

        return response()->json(['success' => true]);
    }
}