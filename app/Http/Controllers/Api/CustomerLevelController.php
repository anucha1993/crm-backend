<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerLevelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = CustomerLevel::withCount('customers');

        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        if ($request->has('active_only')) {
            $query->where('is_active', true);
        }

        $levels = $query->orderBy('sort_order')->orderBy('name')->get();

        return response()->json(['customer_levels' => $levels]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:20',
            'inactive_days' => 'required|integer|min:0',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $level = CustomerLevel::create($request->all());

        return response()->json(['customer_level' => $level], 201);
    }

    public function show(CustomerLevel $customerLevel): JsonResponse
    {
        $customerLevel->loadCount('customers');

        return response()->json(['customer_level' => $customerLevel]);
    }

    public function update(Request $request, CustomerLevel $customerLevel): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'color' => 'nullable|string|max:20',
            'inactive_days' => 'sometimes|integer|min:0',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $customerLevel->update($request->all());

        return response()->json(['customer_level' => $customerLevel]);
    }

    public function destroy(CustomerLevel $customerLevel): JsonResponse
    {
        if ($customerLevel->customers()->exists()) {
            return response()->json(['message' => 'ไม่สามารถลบได้ เนื่องจากมีลูกค้าในระดับนี้อยู่'], 422);
        }

        $customerLevel->delete();

        return response()->json(['message' => 'ลบระดับลูกค้าสำเร็จ']);
    }
}
