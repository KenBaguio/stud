<?php

namespace App\Http\Controllers\ClerkDashboard;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ClerkProposalController extends Controller
{
    /**
     * Get all proposals for a specific customer
     */
    public function getCustomerProposals(Request $request, $customerId): JsonResponse
    {
        try {
            $clerk = $request->user('clerk');
            
            if (!$clerk) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            // Verify customer exists and belongs to this clerk (if you have such relationship)
            $customer = Customer::find($customerId);
            
            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer not found'
                ], 404);
            }

            // Get all messages with proposals sent by clerk to this customer (limit 5)
            $limit = $request->query('limit', 5);
            $limit = min((int) $limit, 100);
            
            $proposalMessages = Message::where('sender_id', $clerk->id)
                ->where('receiver_id', $customerId)
                ->whereNotNull('custom_proposal')
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

            $proposals = [];
            
            foreach ($proposalMessages as $message) {
                $proposalData = json_decode($message->custom_proposal, true);
                
                if ($proposalData) {
                    $proposals[] = [
                        'id' => $message->id,
                        'message_id' => $message->id,
                        'customer_id' => $customerId,
                        'name' => $proposalData['name'] ?? 'Custom Proposal',
                        'product_type' => $proposalData['product_type'] ?? 'apparel',
                        'total_price' => $proposalData['total_price'] ?? 0,
                        'quantity' => $proposalData['quantity'] ?? 1,
                        'customization_request' => $proposalData['customization_request'] ?? null,
                        'material' => $proposalData['material'] ?? null,
                        'features' => $proposalData['features'] ?? [],
                        'size_options' => $proposalData['size_options'] ?? [],
                        'designer_message' => $proposalData['designer_message'] ?? null,
                        'status' => $proposalData['status'] ?? 'pending',
                        'created_at' => $message->created_at,
                        'updated_at' => $message->updated_at,
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'proposals' => $proposals,
                'count' => count($proposals)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch proposals: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Resend a proposal to customer
     */
    public function resendProposal(Request $request, $proposalId): JsonResponse
    {
        try {
            $clerk = $request->user('clerk');
            
            if (!$clerk) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            // Find the original proposal message
            $originalMessage = Message::where('id', $proposalId)
                ->where('sender_id', $clerk->id)
                ->whereNotNull('custom_proposal')
                ->first();

            if (!$originalMessage) {
                return response()->json([
                    'success' => false,
                    'message' => 'Proposal not found'
                ], 404);
            }

            // Create a new message with the same proposal
            $newMessage = Message::create([
                'sender_id' => $clerk->id,
                'receiver_id' => $originalMessage->receiver_id,
                'message' => "I'm resending the custom proposal for your review: " . (json_decode($originalMessage->custom_proposal, true)['name'] ?? 'Custom Proposal'),
                'custom_proposal' => $originalMessage->custom_proposal,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Proposal resent successfully',
                'data' => [
                    'message' => $newMessage
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to resend proposal: ' . $e->getMessage()
            ], 500);
        }
    }
}