<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuCategory;
use Illuminate\Http\Request;

class MenuCategoryController extends Controller
{
    public function index()
    {
        return response()->json(['data' => MenuCategory::orderBy('sort_order')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'emoji' => 'nullable|string|max:10',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);
        return response()->json(['data' => MenuCategory::create($data)], 201);
    }

    public function update(Request $request, MenuCategory $menuCategory)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'emoji' => 'nullable|string|max:10',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);
        $menuCategory->update($data);
        return response()->json(['data' => $menuCategory->fresh()]);
    }

    public function destroy(MenuCategory $menuCategory)
    {
        $menuCategory->delete();
        return response()->json(['message' => 'Kategori dihapus.']);
    }
}
