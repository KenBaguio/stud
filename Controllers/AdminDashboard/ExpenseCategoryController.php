<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;

class ExpenseCategoryController extends Controller
{
    public function index()
    {
        $categories = ExpenseCategory::orderBy('name')->get();
        return response()->json($categories);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:expense_categories,name',
            'description' => 'nullable|string',
        ]);

        $category = ExpenseCategory::create($validated);
        return response()->json($category, 201);
    }

    public function destroy(ExpenseCategory $category)
    {
        $category->delete();
        return response()->json(['message' => 'Category deleted successfully']);
    }
}
