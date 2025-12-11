<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Review;

class ReviewController extends Controller
{
    // Fetch all reviews with user info (walay product)
    public function index(Request $request)
    {
        $limit = $request->query('limit', 5);
        $limit = min((int) $limit, 100);
        $search = $request->query('search');
        $sort = $request->query('sort', 'newest');
        
        $query = Review::with('user:id,first_name,last_name,organization_name,profile_image,is_organization');

        // Search logic
        if ($search) {
             $query->where(function($q) use ($search) {
                 $q->where('comment', 'like', "%{$search}%")
                   ->orWhere('admin_reply', 'like', "%{$search}%")
                   ->orWhereHas('user', function($u) use ($search) {
                        $u->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"])
                          ->orWhere('organization_name', 'like', "%{$search}%");
                   });
             });
        }

        // Sort logic
        switch ($sort) {
            case 'desc': // Highest Rated (UI calls it 'desc', presumably for rating)
                $query->orderBy('rating', 'desc');
                break;
            case 'asc': // Lowest Rated
                $query->orderBy('rating', 'asc');
                break;
            case 'oldest':
                $query->orderBy('created_at', 'asc');
                break;
            case 'newest':
            default:
                $query->orderBy('created_at', 'desc');
                break;
        }

        $reviews = $query->paginate($limit);

        // Format user display name and ensure profile images use R2
        $reviews->getCollection()->transform(function ($review) {
            if ($review->user) {
                $review->user->name = $review->user->is_organization
                    ? $review->user->organization_name
                    : trim($review->user->first_name . ' ' . $review->user->last_name);
                
                // Ensure profile image uses /api/storage/ endpoint for R2
                if ($review->user->profile_image) {
                    $imagePath = ltrim($review->user->profile_image, '/');
                    $review->user->avatar = url('/api/storage/' . $imagePath);
                    $review->user->profile_image_url = url('/api/storage/' . $imagePath);
                } else {
                    $review->user->avatar = null;
                    $review->user->profile_image_url = null;
                }
            }
            
            return $review;
        });

        return response()->json($reviews);
    }

    // Update admin reply and status
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'admin_reply' => 'nullable|string',
            'status'      => 'required|string|in:pending,approved,rejected',
        ]);

        $review = Review::findOrFail($id);
        $review->admin_reply = $request->admin_reply;
        $review->status = $request->status;
        $review->save();

        return response()->json([
            'message' => 'Review updated successfully',
            'review'  => $review
        ]);
    }

    // Delete a review
    public function destroy($id)
    {
        $review = Review::findOrFail($id);
        $review->delete();

        return response()->json(['message' => 'Review deleted']);
    }
}
