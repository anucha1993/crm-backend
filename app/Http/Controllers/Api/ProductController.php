<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::with(['category', 'sizes']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $products = $query->orderBy('created_at', 'desc')->paginate($request->input('per_page', 20));

        return response()->json($products);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|unique:products,code',
            'name' => 'required|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'unit' => 'required|string',
            'unit_custom' => 'nullable|string|max:100',
            'cross_section' => 'nullable|string|max:255',
            'length' => 'nullable|numeric|min:0',
            'length_unit' => 'nullable|string',
            'length_unit_custom' => 'nullable|string|max:100',
            'thickness' => 'nullable|numeric|min:0',
            'thickness_unit' => 'nullable|string',
            'thickness_unit_custom' => 'nullable|string|max:100',
            'steel_type' => 'nullable|string',
            'side_steel' => 'nullable|in:unspecified,hide,show',
            'size_type' => 'nullable|in:standard,custom',
            'custom_note' => 'nullable|string',
            'weight' => 'nullable|numeric|min:0',
            'selling_price' => 'nullable|numeric|min:0',
            'code_ref' => 'nullable|string|max:255',
        ]);

        $product = Product::create($request->all());

        if ($request->has('sizes') && is_array($request->sizes)) {
            foreach ($request->sizes as $i => $size) {
                $product->sizes()->create([
                    'length' => $size['length'] ?? null,
                    'length_unit' => $size['length_unit'] ?? null,
                    'length_unit_custom' => $size['length_unit_custom'] ?? null,
                    'thickness' => $size['thickness'] ?? null,
                    'thickness_unit' => $size['thickness_unit'] ?? null,
                    'thickness_unit_custom' => $size['thickness_unit_custom'] ?? null,
                    'sort_order' => $i,
                ]);
            }
        }

        $product->load(['category', 'sizes']);

        return response()->json(['product' => $product], 201);
    }

    public function show(Product $product): JsonResponse
    {
        $product->load(['category', 'sizes']);

        return response()->json(['product' => $product]);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $request->validate([
            'code' => 'sometimes|string|unique:products,code,' . $product->id,
            'name' => 'sometimes|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'unit' => 'sometimes|string',
            'unit_custom' => 'nullable|string|max:100',
            'cross_section' => 'nullable|string|max:255',
            'length' => 'nullable|numeric|min:0',
            'length_unit' => 'nullable|string',
            'length_unit_custom' => 'nullable|string|max:100',
            'thickness' => 'nullable|numeric|min:0',
            'thickness_unit' => 'nullable|string',
            'thickness_unit_custom' => 'nullable|string|max:100',
            'steel_type' => 'nullable|string',
            'side_steel' => 'nullable|in:unspecified,hide,show',
            'size_type' => 'nullable|in:standard,custom',
            'custom_note' => 'nullable|string',
            'weight' => 'nullable|numeric|min:0',
            'selling_price' => 'nullable|numeric|min:0',
            'code_ref' => 'nullable|string|max:255',
        ]);

        $product->update($request->all());

        if ($request->has('sizes') && is_array($request->sizes)) {
            $product->sizes()->delete();
            foreach ($request->sizes as $i => $size) {
                $product->sizes()->create([
                    'length' => $size['length'] ?? null,
                    'length_unit' => $size['length_unit'] ?? null,
                    'length_unit_custom' => $size['length_unit_custom'] ?? null,
                    'thickness' => $size['thickness'] ?? null,
                    'thickness_unit' => $size['thickness_unit'] ?? null,
                    'thickness_unit_custom' => $size['thickness_unit_custom'] ?? null,
                    'sort_order' => $i,
                ]);
            }
        }

        $product->load(['category', 'sizes']);

        return response()->json(['product' => $product]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(['message' => 'ลบสินค้าสำเร็จ']);
    }
}
