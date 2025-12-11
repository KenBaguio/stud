<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomProposal;
use App\Models\Message;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CustomProposalController extends Controller
{
    /**
     * Clerk: Get all proposals created by the authenticated user.
     */
    public function index(Request $request)
    {
        $userId = auth()->id();
        
        // Add query limit support (default 5, max 100)
        $limit = $request->query('limit', 5);
        $limit = min((int) $limit, 100);

        $proposals = CustomProposal::where('user_id', $userId)
            ->latest()
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'proposals' => $proposals
        ]);
    }

    /**
     * Admin: Get all custom proposals (for dashboard)
     */
    public function adminIndex(Request $request)
    {
        try {
            // Add query limit support (default 5, max 100)
            $limit = $request->query('limit', 5);
            $limit = min((int) $limit, 100);
            
            $proposals = CustomProposal::with(['user', 'customer'])
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $proposals,
                'message' => 'Custom proposals retrieved successfully'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve custom proposals: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Clerk: Create a new custom proposal for a specific customer.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'receiver_id' => 'required|exists:users,id',
            'message' => 'nullable|string',
            'custom_proposal.name' => 'required|string|max:255',
            'custom_proposal.customization_request' => 'nullable|string',
            'custom_proposal.product_type' => 'nullable|string|max:255',
            'custom_proposal.category' => 'required|in:apparel,accessory,gear',
            'custom_proposal.customer_name' => 'nullable|string|max:255',
            'custom_proposal.customer_email' => 'nullable|email',
            'custom_proposal.quantity' => 'nullable|integer',
            'custom_proposal.total_price' => 'nullable|numeric',
            'custom_proposal.designer_message' => 'nullable|string',
            'custom_proposal.material' => 'nullable|string',
            'custom_proposal.features' => 'nullable|array',
            'custom_proposal.images' => 'nullable|array',
            'custom_proposal.size_options' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->input('custom_proposal');

        $proposal = CustomProposal::create([
            'user_id' => auth()->id(),
            'customer_id' => $request->receiver_id,
            'name' => $data['name'],
            'customization_request' => isset($data['customization_request']) ? $data['customization_request'] : null,
            'product_type' => isset($data['product_type']) ? $data['product_type'] : null,
            'category' => $data['category'],
            'customer_name' => isset($data['customer_name']) ? $data['customer_name'] : null,
            'customer_email' => isset($data['customer_email']) ? $data['customer_email'] : null,
            'quantity' => isset($data['quantity']) ? $data['quantity'] : null,
            'total_price' => isset($data['total_price']) ? $data['total_price'] : null,
            'designer_message' => isset($data['designer_message']) ? $data['designer_message'] : null,
            'material' => isset($data['material']) ? $data['material'] : null,
            'features' => isset($data['features']) ? $data['features'] : [],
            'images' => isset($data['images']) ? $data['images'] : [],
            'size_options' => isset($data['size_options']) ? $data['size_options'] : [],
            // STATUS FIELD REMOVED - No more pending/approved/rejected
        ]);

        // Create a linked message for the proposal
        Message::create([
            'sender_id' => auth()->id(),
            'receiver_id' => $request->receiver_id,
            'message' => isset($request->message) ? $request->message : 'Custom proposal sent.',
            'type' => 'proposal',
            'proposal' => json_encode([
                'id' => $proposal->id,
                'name' => $proposal->name,
                'category' => $proposal->category,
                'total_price' => $proposal->total_price,
                'images' => $proposal->images,
                // STATUS REMOVED FROM MESSAGE TOO
            ]),
        ]);

        // Send notification to customer about new proposal
        try {
            $clerk = User::find(auth()->id());
            $clerkName = $clerk ? ($clerk->display_name ?? $clerk->first_name ?? 'Designer') : 'Designer';
            $notification = NotificationService::notifyProposalCreated($proposal, $clerkName);
            
            if (!$notification) {
                \Log::warning("Failed to send notification for proposal {$proposal->id} to customer {$proposal->customer_id}");
            }
        } catch (\Exception $e) {
            \Log::error("Error sending proposal notification: " . $e->getMessage());
            // Don't fail the request if notification fails
        }

        return response()->json([
            'success' => true,
            'message' => 'Custom proposal successfully sent.',
            'proposal' => $proposal,
            'data' => $proposal, // Also include as 'data' for frontend compatibility
        ], 201);
    }

    /**
     * Show a specific proposal by ID.
     */
    public function show($id)
    {
        $proposal = CustomProposal::findOrFail($id);

        return response()->json([
            'success' => true,
            'proposal' => $proposal
        ]);
    }

    /**
     * Clerk/Admin: Update a proposal.
     */
    public function update(Request $request, $id)
    {
        $proposal = CustomProposal::findOrFail($id);
        $proposal->update($request->all());

        return response()->json([
            'message' => 'Proposal updated successfully.',
            'proposal' => $proposal
        ]);
    }

    /**
     * Delete a proposal.
     */
    public function destroy($id)
    {
        CustomProposal::findOrFail($id)->delete();

        return response()->json([
            'message' => 'Proposal deleted successfully.'
        ]);
    }

    /**
     * REMOVED: updateStatus method - no more status updates
     */

    /**
     * Clerk/Admin: Get all proposals for a specific customer.
     */
    public function getCustomerProposals(Request $request, $customerId)
    {
        // Add query limit support (default 5, max 100)
        $limit = $request->query('limit', 5);
        $limit = min((int) $limit, 100);
        
        $proposals = CustomProposal::where('customer_id', $customerId)
            ->latest()
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'proposals' => $proposals
        ]);
    }

    /**
     * Customer: Get all proposals addressed to them.
     */
    public function getMyProposals(Request $request)
    {
        $user = auth()->user();
        
        // Add query limit support (default 5, max 100)
        $limit = $request->query('limit', 5);
        $limit = min((int) $limit, 100);

        $proposals = CustomProposal::where('customer_id', $user->id)
            ->latest()
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'proposals' => $proposals
        ]);
    }
}