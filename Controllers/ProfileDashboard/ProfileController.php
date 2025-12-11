<?php

namespace App\Http\Controllers\ProfileDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use App\Helpers\R2Helper;

class ProfileController extends Controller
{
    /**
     * Show current authenticated user's profile
     */
    public function show(Request $request)
    {
        $user = Auth::user();

        // Attach full URL for profile image from R2
        if ($user->profile_image) {
            // Use API endpoint to serve images (handles R2 URLs properly without duplication)
            $user->profile_image_url = url('/api/storage/' . ltrim($user->profile_image, '/'));
        } else {
            $user->profile_image_url = null;
        }

        return response()->json($user);
    }

    /**
     * Update profile information (PUT)
     */
    public function update(Request $request)
    {
        $user = Auth::user();

        // Common validation
        $rules = [
            'phone' => ['required', 'digits:11', Rule::unique('users')->ignore($user->id)],
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
        ];

        if (!$user->is_organization) {
            // Individual
            $rules['first_name'] = 'required|string|max:255';
            $rules['last_name'] = 'required|string|max:255';
            $rules['dob'] = 'required|date';
        } else {
            // Organization
            $rules['organization_name'] = 'required|string|max:255';
            $rules['date_founded'] = 'required|date';
        }

        $validated = $request->validate($rules);

        // Update fields
        if (!$user->is_organization) {
            $user->first_name = $validated['first_name'];
            $user->last_name = $validated['last_name'];
            $user->dob = $validated['dob'];
        } else {
            $user->organization_name = $validated['organization_name'];
            $user->date_founded = $validated['date_founded'];
        }

        $user->phone = $validated['phone'];
        $user->email = $validated['email'];
        $user->save();

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user,
        ]);
    }

    /**
     * Update profile image (PATCH)
     */
    public function updateProfileImage(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'profile_image' => 'required|image|max:2048', // max 2MB
        ]);

        if ($request->hasFile('profile_image')) {
            // Determine which storage disk to use
            $disk = R2Helper::getStorageDisk();
            
            // Delete old image if exists
            if ($user->profile_image) {
                try {
                    if (Storage::disk($disk)->exists($user->profile_image)) {
                        Storage::disk($disk)->delete($user->profile_image);
                    }
                } catch (\Exception $e) {
                    \Log::warning('Failed to delete old profile image: ' . $e->getMessage());
                }
            }

            // Store image - use R2 if configured, otherwise use public storage
            try {
                $path = $request->file('profile_image')->store('profile_images', $disk);
                $user->profile_image = $path; // Store just the path, not the full URL
                $user->save();
            } catch (\Exception $e) {
                \Log::error('Failed to upload profile image: ' . $e->getMessage());
                return response()->json([
                    'message' => 'Failed to upload image. Please check R2 configuration.',
                    'error' => config('app.debug') ? $e->getMessage() : 'Upload failed'
                ], 500);
            }

            // Use API endpoint to serve images (handles R2 URLs properly without duplication)
            $imageUrl = url('/api/storage/' . ltrim($path, '/'));
            
            return response()->json([
                'message' => 'Profile image updated successfully',
                'profile_image' => $user->profile_image, // Return the path for frontend to construct URL
                'profile_image_url' => $imageUrl, // API endpoint URL that handles R2 properly
                'user' => $user->fresh(), // Return updated user data
            ]);
        }

        return response()->json([
            'message' => 'No image file provided',
        ], 400);
    }
}
