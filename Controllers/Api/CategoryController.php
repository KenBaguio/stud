<?php

namespace App\Http\Controllers\Api;

use Illuminate\Routing\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function __construct()
    {
        // Public access for index
        $this->middleware('jwt.auth')->except(['index']);
        // Admin-only access for other actions
        $this->middleware(function($request, $next){
            if(auth()->check() && auth()->user()->role !== 'admin') {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
            return $next($request);
        })->except(['index']);
    }

    /**
     * List all categories with product counts
     */
    public function index(Request $request)
    {
        try {
            $limit = (int) $request->query('limit', 50);
            $limit = $limit < 1 ? null : min($limit, 500);
            $query = Category::withCount('products')->orderByDesc('created_at');

            if ($search = $request->query('search')) {
                $query->where('name', 'like', "%{$search}%");
            }

            if ($limit) {
                $query->limit($limit);
            }

            $categories = $query->get();

            return response()->json($categories, 200);
        } catch (\Exception $e) {
            Log::error('Category Index Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            // Return empty array on error instead of error response
            return response()->json([]);
        }
    }

    /**
     * Store a new category
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'name' => [
                    'required',
                    'string',
                    Rule::unique('categories')->whereNull('deleted_at')
                ],
                'type' => 'required|in:apparel,accessories' // Fixed: lowercase values to match frontend
            ]);

            $category = Category::create([
                'name' => $request->name,
                'type' => $request->type
            ]);

            return response()->json($category, 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation Failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Category Store Error: '.$e->getMessage());
            return response()->json([
                'message' => 'Server Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update an existing category
     */
    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'name' => [
                    'required',
                    'string',
                    Rule::unique('categories')->ignore($id)->whereNull('deleted_at')
                ],
                'type' => 'required|in:apparel,accessories' // Fixed: lowercase values to match frontend
            ]);

            $category = Category::findOrFail($id);
            $category->update([
                'name' => $request->name,
                'type' => $request->type
            ]);

            return response()->json($category, 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation Failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Category Update Error: '.$e->getMessage());
            return response()->json([
                'message' => 'Server Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Soft delete a category (admin only)
     */
    public function destroy($id)
    {
        try {
            $category = Category::findOrFail($id);

            $category->delete(); // soft delete

            return response()->json([
                'message' => 'Category deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Category Delete Error: '.$e->getMessage());
            return response()->json([
                'message' => 'Server Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}