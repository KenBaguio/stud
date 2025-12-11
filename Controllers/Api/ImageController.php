<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Helpers\R2Helper;

class ImageController extends Controller
{
    public function placeholder(Request $request, $width, $height)
    {
        $width = max(1, min(2000, (int) $width));
        $height = max(1, min(2000, (int) $height));
        $text = $request->query('text', "{$width}×{$height}");

        $placeholderUrl = sprintf(
            'https://placehold.co/%dx%d?text=%s',
            $width,
            $height,
            urlencode($text)
        );

        $response = Http::get($placeholderUrl);

        if (!$response->successful()) {
            return response()->json(['message' => 'Unable to generate placeholder image'], 500);
        }

        $contentType = $response->header('Content-Type');
        if (is_array($contentType)) {
            $contentType = $contentType[0] ?? 'image/png';
        }
        return response($response->body(), 200)
            ->header('Content-Type', $contentType ?: 'image/png');
    }

    public function serveStorageImage($path)
    {
        // Remove /storage/ prefix if present (from database format)
        $cleanPath = ltrim($path, '/');
        if (strpos($cleanPath, 'storage/') === 0) {
            $cleanPath = substr($cleanPath, 8); // Remove 'storage/' prefix
        }
        
        try {
            // Try R2 first (for Cloudflare R2) if configured
            if (R2Helper::isConfigured() && Storage::disk('r2')->exists($cleanPath)) {
                // Serve the file directly from R2 instead of redirecting
                // This avoids 400 errors from direct R2 URLs
                $fileContents = Storage::disk('r2')->get($cleanPath);
                
                // Determine MIME type from file extension
                $extension = pathinfo($cleanPath, PATHINFO_EXTENSION);
                $mimeTypes = [
                    'jpg' => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'gif' => 'image/gif',
                    'webp' => 'image/webp',
                    'svg' => 'image/svg+xml'
                ];
                $mimeType = $mimeTypes[strtolower($extension)] ?? 'image/jpeg';
                
                if ($fileContents) {
                    return response($fileContents, 200)
                        ->header('Content-Type', $mimeType)
                        ->header('Cache-Control', 'public, max-age=31536000') // Cache for 1 year
                        ->header('Access-Control-Allow-Origin', '*'); // CORS header
                }
            }
        } catch (\Exception $e) {
            \Log::warning('R2 storage error: ' . $e->getMessage(), [
                'path' => $cleanPath,
                'original_path' => $path,
                'trace' => $e->getTraceAsString()
            ]);
        }
        
        // Fallback to local storage (for backward compatibility)
        $filePath = storage_path('app/public/' . $cleanPath);
        if (file_exists($filePath)) {
            $file = new \Symfony\Component\HttpFoundation\File\File($filePath);
            $type = $file->getMimeType();
            
            return response(file_get_contents($filePath), 200)
                ->header('Content-Type', $type)
                ->header('Cache-Control', 'public, max-age=31536000')
                ->header('Access-Control-Allow-Origin', '*'); // CORS header
        }
        
        return redirect("/api/placeholder/400/300?text=Image+Not+Found");
    }

    public function serveChatImage($filename)
    {
        $path = 'chat-images/' . $filename;
        
        // Try R2 first (for Cloudflare R2) if configured
        if (R2Helper::isConfigured() && Storage::disk('r2')->exists($path)) {
            return redirect(R2Helper::getR2Url($path));
        }
        
        // Fallback to local storage (for backward compatibility)
        $filePath = storage_path('app/public/' . $path);
        if (file_exists($filePath)) {
            $file = new \Symfony\Component\HttpFoundation\File\File($filePath);
            $type = $file->getMimeType();
            
            return response(file_get_contents($filePath), 200)
                ->header('Content-Type', $type);
        }
        
        return redirect("/api/placeholder/400/300?text=Chat+Image+Not+Found");
    }

    public function uploadChatImages(Request $request)
    {
        $request->validate([
            'images.*' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240'
        ]);

        $uploadedUrls = [];
        
        if ($request->hasFile('images')) {
            $disk = R2Helper::getStorageDisk();
            foreach ($request->file('images') as $image) {
                try {
                    $path = $image->store('chat-images', $disk);
                    // Use /api/storage/ endpoint for images (handles R2 URLs properly)
                    $uploadedUrls[] = url('/api/storage/' . ltrim($path, '/'));
                } catch (\Exception $e) {
                    \Log::error('Failed to upload chat image: ' . $e->getMessage());
                    // Continue with other images
                }
            }
        }
        
        return response()->json([
            'success' => true,
            'imageUrls' => $uploadedUrls
        ]);
    }

    public function uploadProposalImages(Request $request)
    {
        $request->validate([
            'images.*' => 'required|image|mimes:jpeg,png,jpg,gif,webp,avif|max:10240'
        ]);

        $uploadedUrls = [];
        
        if ($request->hasFile('images')) {
            $disk = R2Helper::getStorageDisk();
            foreach ($request->file('images') as $image) {
                try {
                    $path = $image->store('proposal-images', $disk);
                    // Use /api/storage/ endpoint for images (handles R2 URLs properly)
                    $uploadedUrls[] = url('/api/storage/' . ltrim($path, '/'));
                } catch (\Exception $e) {
                    \Log::error('Failed to upload proposal image: ' . $e->getMessage());
                    // Continue with other images
                }
            }
        }
        
        return response()->json([
            'success' => true,
            'imageUrls' => $uploadedUrls
        ]);
    }
}