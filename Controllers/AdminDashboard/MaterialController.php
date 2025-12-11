<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\MaterialUsageHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use App\Helpers\R2Helper;

class MaterialController extends Controller
{
    // Get all materials
    public function index(Request $request)
    {
        $limit = $request->query('limit', 5);
        $limit = min((int) $limit, 100);
        $search = $request->query('search');
        $type = $request->query('type');
        $stockStatus = $request->query('stock_status');
        
        $query = Material::query();

        if ($search) {
             $query->where(function($q) use ($search) {
                 $q->where('name', 'like', "%{$search}%")
                   ->orWhere('type', 'like', "%{$search}%");
             });
        }

        if ($type) {
            $query->where('type', $type);
        }

        if ($stockStatus) {
            if ($stockStatus === 'low') {
                $query->where('quantity', '<=', 10);
            } elseif ($stockStatus === 'adequate') {
                $query->where('quantity', '>', 10);
            }
        }

        $materials = $query->orderBy('name', 'asc')->paginate($limit);

        // Calculate global stats (not dependent on pagination)
        $totalInventoryValue = Material::sum(\DB::raw('quantity * cost'));
        
        // "Most Used" Logic: Currently using highest quantity as proxy
        $mostUsed = Material::orderBy('quantity', 'desc')->first(); 
        
        // Get all unique types for filtering
        $allTypes = Material::distinct()->pluck('type')->filter()->values();

        $customResponse = array_merge($materials->toArray(), [
            'stats' => [
                'total_inventory_value' => $totalInventoryValue,
                'most_used_material' => $mostUsed,
                'all_types' => $allTypes
            ]
        ]);

        return response()->json($customResponse);
    }

    // Store new material
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'type'     => 'required|string|max:255',
            'quantity' => 'required|integer|min:0',
            'cost'     => 'required|numeric|min:0',
            'image'    => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        if ($request->hasFile('image')) {
            $disk = R2Helper::getStorageDisk();
            try {
                $path = $request->file('image')->store('materials', $disk);
                $validated['image'] = $path;
            } catch (\Exception $e) {
                \Log::error('Failed to upload material image: ' . $e->getMessage());
                return response()->json(['message' => 'Failed to upload image'], 500);
            }
        }

        $material = Material::create($validated);

        return response()->json($material, 201);
    }

    // Update existing material
    public function update(Request $request, Material $material)
    {
        $validated = $request->validate([
            'name'     => 'sometimes|required|string|max:255',
            'type'     => 'sometimes|required|string|max:255',
            'quantity' => 'sometimes|required|integer|min:0',
            'cost'     => 'sometimes|required|numeric|min:0',
            'image'    => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        if ($request->hasFile('image')) {
            $disk = R2Helper::getStorageDisk();
            if ($material->image) {
                try {
                    Storage::disk($disk)->delete($material->image);
                } catch (\Exception $e) {
                    \Log::warning('Failed to delete old material image: ' . $e->getMessage());
                }
            }
            try {
                $path = $request->file('image')->store('materials', $disk);
                $validated['image'] = $path;
            } catch (\Exception $e) {
                \Log::error('Failed to upload material image: ' . $e->getMessage());
                return response()->json(['message' => 'Failed to upload image'], 500);
            }
        }

        $material->update($validated);

        return response()->json($material);
    }

    // Delete material
    public function destroy(Material $material)
    {
        if ($material->image) {
            $disk = R2Helper::getStorageDisk();
            try {
                Storage::disk($disk)->delete($material->image);
            } catch (\Exception $e) {
                \Log::warning('Failed to delete material image: ' . $e->getMessage());
            }
        }

        $material->delete();

        return response()->json(['message' => 'Material deleted successfully']);
    }

    // Restock
    public function restock(Request $request, Material $material)
    {
        $validated = $request->validate([
            'restock' => 'nullable|integer|min:0',
            'used'    => 'nullable|integer|min:0',
        ]);

        $restock = $validated['restock'] ?? 0;
        $used    = $validated['used'] ?? 0;

        $material->quantity += $restock;
        $material->quantity -= $used;
        if ($material->quantity < 0) {
            $material->quantity = 0;
        }

        // Calculate and add the cost of used quantity to total_used_cost
        if ($used > 0) {
            $usedCost = $used * $material->cost;
            $material->total_used_cost = ($material->total_used_cost ?? 0) + $usedCost;
            
            // Create usage history record
            MaterialUsageHistory::create([
                'material_id' => $material->id,
                'quantity_used' => $used,
                'cost_per_unit' => $material->cost,
                'total_cost' => $usedCost,
            ]);
        }

        $material->save();

        return response()->json($material);
    }

    // Get total material cost used by date range
    public function getMaterialCostUsed(Request $request)
    {
        $range = $request->get('range', 'month');
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        // Get date range
        if ($range === 'custom' && $startDate && $endDate) {
            $start = Carbon::parse($startDate)->startOfDay();
            $end = Carbon::parse($endDate)->endOfDay();
        } else {
            $now = Carbon::now();
            switch ($range) {
                case 'today':
                    $start = $now->copy()->startOfDay();
                    $end = $now->copy()->endOfDay();
                    break;
                case 'week':
                    $start = $now->copy()->subDays(7)->startOfDay();
                    $end = $now->copy()->endOfDay();
                    break;
                case 'month':
                default:
                    $start = $now->copy()->startOfMonth();
                    $end = $now->copy()->endOfMonth();
                    break;
            }
        }

        // Calculate total cost from usage history
        $totalCost = MaterialUsageHistory::whereBetween('created_at', [$start, $end])
            ->sum('total_cost');

        return response()->json([
            'total_cost' => round($totalCost, 2),
            'date_range' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'range' => $range,
        ]);
    }
}
