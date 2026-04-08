<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = VehicleType::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->has('active_only')) {
            $query->where('is_active', true);
        }

        $vehicleTypes = $query->orderBy('sort_order')->orderBy('name')->get();

        return response()->json(['vehicle_types' => $vehicleTypes]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|unique:vehicle_types,code',
            'name' => 'required|string|max:255',
            'min_weight' => 'required|numeric|min:0',
            'max_weight' => 'required|numeric|gt:min_weight',
            'weight_unit' => 'nullable|string|max:50',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $vehicleType = VehicleType::create($request->all());

        return response()->json(['vehicle_type' => $vehicleType], 201);
    }

    public function show(VehicleType $vehicleType): JsonResponse
    {
        return response()->json(['vehicle_type' => $vehicleType]);
    }

    public function update(Request $request, VehicleType $vehicleType): JsonResponse
    {
        $request->validate([
            'code' => 'sometimes|string|unique:vehicle_types,code,' . $vehicleType->id,
            'name' => 'sometimes|string|max:255',
            'min_weight' => 'sometimes|numeric|min:0',
            'max_weight' => 'sometimes|numeric|gt:min_weight',
            'weight_unit' => 'nullable|string|max:50',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $vehicleType->update($request->all());

        return response()->json(['vehicle_type' => $vehicleType]);
    }

    public function destroy(VehicleType $vehicleType): JsonResponse
    {
        $vehicleType->delete();

        return response()->json(['message' => 'ลบประเภทรถสำเร็จ']);
    }

    public function suggest(Request $request): JsonResponse
    {
        $request->validate([
            'weight' => 'required|numeric|min:0',
        ]);

        $weight = $request->weight;

        $suggestions = VehicleType::where('is_active', true)
            ->where('max_weight', '>=', $weight)
            ->orderBy('max_weight')
            ->get();

        // If no match, suggest the largest available
        if ($suggestions->isEmpty()) {
            $suggestions = VehicleType::where('is_active', true)
                ->orderByDesc('max_weight')
                ->limit(1)
                ->get();
        }

        return response()->json(['suggestions' => $suggestions]);
    }
}
