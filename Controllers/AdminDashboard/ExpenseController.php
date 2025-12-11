<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        try {
            $dateRange = $this->getDateRange($request);
            
            $query = Expense::query();
            
            // Filter by date range if provided
            if ($dateRange) {
                $query->whereBetween('expense_date', [$dateRange['start'], $dateRange['end']]);
            }
            
            // Calculate total amount for all matching records before pagination
            $total = $query->sum('amount');

            // Pagination
            $limit = $request->query('limit', 10);
            $limit = min((int) $limit, 100);
            $expenses = $query->orderBy('expense_date', 'desc')->paginate($limit);
            
            return response()->json([
                'success' => true,
                'data' => $expenses,
                'total' => $total
            ]);
        } catch (\Exception $e) {
            Log::error('Expense Index Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch expenses'
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'expense_date' => 'required|date',
            'description' => 'nullable|string',
            'receipt_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($request->hasFile('receipt_image')) {
            $path = $request->file('receipt_image')->store('expenses', 'public');
            $validated['receipt_image'] = $path;
        }

        try {
            $expense = Expense::create($validated);
            return response()->json($expense, 201);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Expense Create Error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to create expense: ' . $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, Expense $expense)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'category' => 'sometimes|required|string|max:255',
            'amount' => 'sometimes|required|numeric|min:0',
            'expense_date' => 'sometimes|required|date',
            'description' => 'nullable|string',
        ]);

        $expense->update($validated);
        return response()->json($expense);
    }

    public function destroy(Expense $expense)
    {
        $expense->delete();
        return response()->json(['message' => 'Expense deleted successfully']);
    }

    private function getDateRange(Request $request)
    {
        $range = $request->get('range', 'month');
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        if ($range === 'custom' && $startDate && $endDate) {
            return [
                'start' => Carbon::parse($startDate)->startOfDay(),
                'end' => Carbon::parse($endDate)->endOfDay()
            ];
        }

        return $this->getPredefinedRange($range);
    }

    private function getPredefinedRange($range)
    {
        $now = Carbon::now();

        switch ($range) {
            case 'today':
                return [
                    'start' => $now->copy()->startOfDay(),
                    'end' => $now->copy()->endOfDay()
                ];
            case 'yesterday':
                return [
                    'start' => $now->copy()->subDay()->startOfDay(),
                    'end' => $now->copy()->subDay()->endOfDay()
                ];
            case 'week':
                return [
                    'start' => $now->copy()->startOfWeek(),
                    'end' => $now->copy()->endOfWeek()
                ];
            case 'month':
                return [
                    'start' => $now->copy()->startOfMonth(),
                    'end' => $now->copy()->endOfMonth()
                ];
            case 'quarter':
                return [
                    'start' => $now->copy()->startOfQuarter(),
                    'end' => $now->copy()->endOfQuarter()
                ];
            case 'year':
                return [
                    'start' => $now->copy()->startOfYear(),
                    'end' => $now->copy()->endOfYear()
                ];
            case 'all':
                return null;
            default:
                return [
                    'start' => $now->copy()->startOfMonth(),
                    'end' => $now->copy()->endOfMonth()
                ];
        }
    }
}
