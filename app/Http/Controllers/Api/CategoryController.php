<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Category::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $categories = $query->withCount('products')->orderBy('name')->get();

        return response()->json(['categories' => $categories]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|unique:categories,code',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $category = Category::create($request->only('code', 'name', 'description'));

        return response()->json(['category' => $category], 201);
    }

    public function show(Category $category): JsonResponse
    {
        $category->loadCount('products');

        return response()->json(['category' => $category]);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $request->validate([
            'code' => 'sometimes|string|unique:categories,code,' . $category->id,
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
        ]);

        $category->update($request->only('code', 'name', 'description'));

        return response()->json(['category' => $category]);
    }

    public function destroy(Category $category): JsonResponse
    {
        if ($category->products()->exists()) {
            return response()->json(['message' => 'ไม่สามารถลบหมวดหมู่ที่มีสินค้าอยู่ได้'], 422);
        }

        $category->delete();

        return response()->json(['message' => 'ลบหมวดหมู่สำเร็จ']);
    }
}
