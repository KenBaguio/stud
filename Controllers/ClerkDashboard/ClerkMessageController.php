<?php

namespace App\Http\Controllers\ClerkDashboard;

use App\Http\Controllers\Controller;    
use Illuminate\Http\Request;
use App\Models\Message;
use App\Models\Conversation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Events\MessageSent;
use App\Services\NotificationService;
use App\Helpers\R2Helper;

class ClerkMessageController extends Controller
{
    // GET /clerk/messages?customer_id={id}
    // IMPORTANT: Clerk can see messages from ALL customers/VIPs
    // This is like customer service - multiple clerks exist, but each customer/VIP has one conversation
    // When clerk views a specific customer, they see ALL messages from that customer's conversation
    // No filtering by clerk_id - clerk can see messages from any clerk in the conversation
    // 
    // BIDIRECTIONAL MESSAGING:
    // - Fetches messages from customer to clerk (customer->clerk)
    // - Fetches messages from clerk to customer (clerk->customer)
    // - All messages in the conversation are returned, regardless of sender/receiver
    public function index(Request $request)
    {
        $customerId = $request->query('customer_id');
        if (!$customerId) return response()->json(['error'=>'customer_id required'],400);

        // Get or create conversation for this customer/VIP
        // Each customer/VIP has ONE conversation (like customer service)
        $conversation = Conversation::firstOrCreate(
            ['user_id' => $customerId],
            ['created_at' => now(), 'updated_at' => now()]
        );

        // Fetch ALL messages from the conversation (from customer/VIP and ALL clerks)
        // IMPORTANT: No filtering by sender_id, receiver_id, or clerk_id
        // This ensures clerk can see ALL messages in the conversation, including messages from other clerks
        // Multiple clerks can exist, but all their messages to this customer are in the same conversation
        
        $afterId = $request->query('after_id');
        $beforeId = $request->query('before_id');
        
        $messagesQuery = $conversation->messages()
            ->with(['sender', 'receiver']);
        
        // For polling new messages (after_id), get messages after the specified ID
        if ($afterId) {
            $messagesQuery->where('id', '>', $afterId)
                ->orderBy('created_at', 'asc'); // Ascending for new messages
        } elseif ($beforeId) {
            // For pagination (before_id), get older messages before the specified ID
            $messagesQuery->where('id', '<', $beforeId)
                ->orderBy('created_at', 'desc') // Descending to get most recent older messages first
                ->limit($request->query('limit', 5));
        } else {
            // For initial load, get the latest messages (most recent first, then we'll reverse)
            // This ensures we show the most recent messages in the chat
            $messagesQuery->orderBy('created_at', 'desc');
        }

        // Add query limit support (default 5, max 100)
        // Only apply limit if not already set (for before_id case)
        if (!$beforeId) {
            $limit = $request->query('limit', 5);
            $limit = min((int) $limit, 100);
            $messagesQuery->limit($limit);
        }

        $messages = $messagesQuery->get();
        
        // Reverse messages for initial load and pagination to get chronological order (oldest first)
        // This is needed because we query DESC for initial load and before_id to get latest messages
        // For after_id, messages are already in ASC order (chronological)
        if (!$afterId) {
            $messages = $messages->reverse();
        }

        // Parse message data for each message and ensure sender/receiver info is included
        // IMPORTANT: This ensures bidirectional message fetching - both clerk->customer and customer->clerk messages
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
                    'profile_image' => $message->receiver->profile_image,
                ];
            }
            
            return $parsed;
        });

        $conversation->load(['activeClerk', 'user']);

        return response()->json([
            'conversation' => $conversation,
            'messages' => $messages->values()->toArray(), // Ensure array format for frontend compatibility
        ]);
    }

    // POST /clerk/messages/send
    public function sendMessage(Request $request)
    {
        $validated = $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'conversation_id' => 'nullable|exists:conversations,id',
            'message' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:10240',
        ]);

        // Handle image uploads - store in R2 and use /api/storage/ endpoint
        $uploadedImages = [];
        if ($request->hasFile('images')) {
            $disk = R2Helper::getStorageDisk();
            foreach ($request->file('images') as $image) {
                try {
                    $path = $image->store('chat-images', $disk);
                    // Use /api/storage/ endpoint for images
                    $uploadedImages[] = url('/api/storage/' . ltrim($path, '/'));
                } catch (\Exception $e) {
                    \Log::error('Failed to upload clerk chat image: ' . $e->getMessage());
                }
            }
        }

        $conversation = null;
        if (!empty($validated['conversation_id'])) {
            $conversation = Conversation::where('id', $validated['conversation_id'])
                ->where('user_id', $request->receiver_id)
                ->first();
        }

        if (!$conversation) {
            $conversation = Conversation::firstOrCreate(
                ['user_id' => $request->receiver_id],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        $messageData = [
            'conversation_id' => $conversation->id,
            'sender_id' => Auth::id(),
            'receiver_id' => $conversation->user_id,
            'message' => $request->message,
        ];

        // Add images to message if any were uploaded
        if (!empty($uploadedImages)) {
            $messageData['images'] = json_encode($uploadedImages);
        }

        $message = Message::create($messageData);

        // Load relationships
        $message->load(['sender', 'receiver']);

        // Parse message data for response
        $messageData = $this->parseMessageData($message);
        
        // Ensure sender and receiver information is included in response
        if ($message->sender) {
            $messageData->sender = [
                'id' => $message->sender->id,
                'first_name' => $message->sender->first_name,
                'last_name' => $message->sender->last_name,
                'email' => $message->sender->email,
                'role' => $message->sender->role,
                'profile_image' => $message->sender->profile_image,
            ];
        }
        
        if ($message->receiver) {
            $messageData->receiver = [
                'id' => $message->receiver->id,
                'first_name' => $message->receiver->first_name,
                'last_name' => $message->receiver->last_name,
                'email' => $message->receiver->email,
                'role' => $message->receiver->role,
                'profile_image' => $message->receiver->profile_image,
            ];
        }

        // Update active_clerk_id to the current clerk
        // IMPORTANT: This is just for tracking - it doesn't filter messages
        // - Customers/VIPs can see messages from ALL clerks in their conversation
        // - Clerks can see messages from ALL customers/VIPs (multiple conversations)
        // - Multiple clerks can exist, but each customer/VIP has one conversation
        $conversation->forceFill([
            'active_clerk_id' => Auth::id(),
            'last_message_at' => now(),
            'updated_at' => now(),
        ])->save();

        // Broadcast the event
        broadcast(new MessageSent($message));

        // Notify customer about the new message
        NotificationService::notifyChatMessage($message, $message->receiver);

        return response()->json($messageData, 201);
    }

    // POST /clerk/messages/send-product
    public function sendProduct(Request $request)
    {
        $validated = $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'conversation_id' => 'nullable|exists:conversations,id',
            'product' => 'required|array',
            'images' => 'nullable|array',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:10240',
        ]);

        // Handle image uploads for product - store in R2 and use /api/storage/ endpoint
        $uploadedImages = [];
        if ($request->hasFile('images')) {
            $disk = R2Helper::getStorageDisk();
            foreach ($request->file('images') as $image) {
                try {
                    $path = $image->store('chat-images', $disk);
                    // Use /api/storage/ endpoint for images
                    $uploadedImages[] = url('/api/storage/' . ltrim($path, '/'));
                } catch (\Exception $e) {
                    \Log::error('Failed to upload clerk product image: ' . $e->getMessage());
                }
            }
        }

        // Merge uploaded images with product images if any
        $productData = $request->product;
        if (!empty($uploadedImages)) {
            $existingImages = $productData['images'] ?? [];
            $productData['images'] = array_merge($existingImages, $uploadedImages);
        }

        $conversation = null;
        if (!empty($validated['conversation_id'])) {
            $conversation = Conversation::where('id', $validated['conversation_id'])
                ->where('user_id', $request->receiver_id)
                ->first();
        }

        if (!$conversation) {
            $conversation = Conversation::firstOrCreate(
                ['user_id' => $request->receiver_id],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => Auth::id(),
            'receiver_id' => $conversation->user_id,
            'message' => $productData['note'] ?? 'Here\'s a product suggestion for you!',
            'product' => json_encode($productData),
        ]);

        // Load relationships
        $message->load(['sender', 'receiver']);

        // Parse message data for response
        $messageData = $this->parseMessageData($message);
        $messageData['isProductReference'] = true;
        
        // Ensure sender and receiver information is included in response
        if ($message->sender) {
            $messageData->sender = [
                'id' => $message->sender->id,
                'first_name' => $message->sender->first_name,
                'last_name' => $message->sender->last_name,
                'email' => $message->sender->email,
                'role' => $message->sender->role,
                'profile_image' => $message->sender->profile_image,
            ];
        }
        
        if ($message->receiver) {
            $messageData->receiver = [
                'id' => $message->receiver->id,
                'first_name' => $message->receiver->first_name,
                'last_name' => $message->receiver->last_name,
                'email' => $message->receiver->email,
                'role' => $message->receiver->role,
                'profile_image' => $message->receiver->profile_image,
            ];
        }

        // Update active_clerk_id to the current clerk
        // IMPORTANT: This is just for tracking - it doesn't filter messages
        // - Customers/VIPs can see messages from ALL clerks in their conversation
        // - Clerks can see messages from ALL customers/VIPs (multiple conversations)
        // - Multiple clerks can exist, but each customer/VIP has one conversation
        $conversation->forceFill([
            'active_clerk_id' => Auth::id(),
            'last_message_at' => now(),
            'updated_at' => now(),
        ])->save();

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

        // Parse images data and ensure URLs use /api/storage/ endpoint
        if ($message->images && is_string($message->images)) {
            try {
                $images = json_decode($message->images, true);
                // Convert image URLs to use /api/storage/ endpoint if they're R2 paths
                if (is_array($images)) {
                    $message->images = array_map(function($img) {
                        // If it's already a full URL starting with http, check if it's an R2 URL
                        if (is_string($img) && (strpos($img, 'http://') === 0 || strpos($img, 'https://') === 0)) {
                            // If it's an R2 URL, convert to /api/storage/ endpoint
                            if (strpos($img, '.r2.cloudflarestorage.com') !== false || strpos($img, '/chat-images/') !== false) {
                                // Extract the path after the domain
                                $path = parse_url($img, PHP_URL_PATH);
                                if ($path) {
                                    return url('/api/storage/' . ltrim($path, '/'));
                                }
                            }
                            return $img; // Keep as is if not R2 URL
                        }
                        // If it's a relative path, use /api/storage/ endpoint
                        if (is_string($img) && strpos($img, '/') === 0) {
                            return url('/api/storage/' . ltrim($img, '/'));
                        }
                        // If it's a path without leading slash, add it
                        if (is_string($img) && strpos($img, 'http') !== 0) {
                            return url('/api/storage/' . ltrim($img, '/'));
                        }
                        return $img;
                    }, $images);
                } else {
                    $message->images = [];
                }
            } catch (\Exception $e) {
                $message->images = [];
            }
        }

        $message->isProductReference = !!$message->product;
        $message->hasImages = !empty($message->images);

        return $message;
    }
}