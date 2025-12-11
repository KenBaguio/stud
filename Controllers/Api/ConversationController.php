<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Conversation;
use App\Models\Message;
use App\Events\MessageSent;

class ConversationController extends Controller
{
    // Customer: get their own conversations
    public function userConversations(Request $request) {
        $userId = auth()->user()->id;
        $limit = $request->query('limit', 5);
        $limit = min((int) $limit, 100);
        $conversations = Conversation::with('clerk')
            ->where('user_id', $userId)
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get();
        return response()->json($conversations);
    }

    // Clerk: get ALL customer conversations (shared inbox model)
    // All clerks can see all customer conversations, like a customer service inbox
    public function clerkConversations(Request $request) {
        $user = auth()->user();
        
        // Only clerks and admins can access this
        if (!in_array($user->role, ['clerk', 'admin'])) {
            abort(403, 'Only clerks and admins can access conversations');
        }
        
        // Add query limit support (default 5, max 100)
        $limit = $request->query('limit', 5);
        $limit = min((int) $limit, 100);
        
        // Return ALL conversations with customers (no filtering by assignment)
        // This ensures all clerks can see and respond to any customer conversation
        $conversations = Conversation::with(['user', 'activeClerk'])
            ->whereHas('user', function($query) {
                $query->whereIn('role', ['user', 'customer', 'vip', 'organization']);
            })
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get();
        
        // Add last message to each conversation and ensure profile images use R2
        $conversations->each(function($conversation) {
            $lastMessage = Message::where('conversation_id', $conversation->id)
                ->orderBy('created_at', 'desc')
                ->first();
            $conversation->last_message = $lastMessage;
            
            // Ensure user profile image uses /api/storage/ endpoint for R2
            if ($conversation->user && $conversation->user->profile_image) {
                $imagePath = ltrim($conversation->user->profile_image, '/');
                $conversation->user->profile_image_url = url('/api/storage/' . $imagePath);
            }
        });
            
        return response()->json($conversations);
    }

    // Create or return existing conversation
    public function store(Request $r) {
        $r->validate(['user_id'=>'required|exists:users,id','clerk_id'=>'nullable|exists:users,id']);
        $conv = Conversation::firstOrCreate([
            'user_id' => $r->user_id,
            'clerk_id' => $r->clerk_id ?? null,
        ]);
        return response()->json($conv);
    }

    // Get messages for a conversation
    public function messages(Conversation $conversation, Request $request) {
        // ensure auth: user must be part of conversation
        $this->authorizeConversation($conversation);
        $limit = $request->query('limit', 5);
        $limit = min((int) $limit, 100);
        $messages = Message::where('conversation_id', $conversation->id)
            ->with('sender')
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();
        return response()->json($messages);
    }

    // Send message
    public function sendMessage(Request $r, Conversation $conversation) {
        $this->authorizeConversation($conversation);
        $r->validate(['message'=>'required|string']);
        $message = Message::create([
            'conversation_id'=>$conversation->id,
            'sender_id'=>auth()->user()->id,
            'message'=>$r->message
        ]);
        // fire event
        broadcast(new MessageSent($message))->toOthers();
        // update conversation updated_at so lists sort
        $conversation->touch();
        return response()->json($message);
    }

    protected function authorizeConversation(Conversation $conversation) {
        $user = auth()->user();
        
        // Customer can always access their own conversation
        if ($user->id === $conversation->user_id) {
            return;
        }
        
        // ANY clerk or admin can access ANY conversation (shared inbox model)
        // This allows all clerks to see and respond to customer messages
        if (in_array($user->role, ['clerk', 'admin'])) {
            return;
        }
        
        // Fallback: if conversation has an assigned clerk, they can access it
        if ($conversation->clerk_id && $user->id === $conversation->clerk_id) {
            return;
        }
        
        // Otherwise, not authorized
        abort(403, 'Not authorized to access this conversation');
    }

    // Extra: list all customers (for clerk to start conversations)
    public function customersList() {
        $customers = \App\Models\User::where('role','user')->select('id','name','email')->get();
        return response()->json($customers);
    }
}
