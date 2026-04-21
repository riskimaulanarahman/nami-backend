<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Http\Resources\MenuItemResource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MenuItemController extends Controller
{
    public function index()
    {
        return MenuItemResource::collection(
            MenuItem::with(['category', 'recipes.ingredient'])->orderBy('sort_order')->get()
        );
    }

    public function store(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => ['required', Rule::exists('menu_categories', 'id')->where('tenant_id', $tenantId)],
            'price' => 'required|integer|min:0',
            'cost' => 'integer|min:0',
            'emoji' => 'nullable|string|max:10',
            'description' => 'nullable|string',
            'is_available' => 'boolean',
            'recipe' => 'nullable|array',
            'recipe.*.ingredient_id' => ['required_with:recipe', Rule::exists('ingredients', 'id')->where('tenant_id', $tenantId)],
            'recipe.*.quantity' => 'required_with:recipe|numeric|min:0.0001',
        ]);

        $menuItem = MenuItem::create(collect($data)->except('recipe')->toArray());

        if (!empty($data['recipe'])) {
            foreach ($data['recipe'] as $r) {
                $menuItem->recipes()->create($r);
            }
        }

        return new MenuItemResource($menuItem->fresh()->load(['category', 'recipes.ingredient']));
    }

    public function show(MenuItem $menuItem)
    {
        return new MenuItemResource($menuItem->load(['category', 'recipes.ingredient']));
    }

    public function update(Request $request, MenuItem $menuItem)
    {
        $tenantId = $request->user()?->tenant_id;

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'category_id' => ['sometimes', Rule::exists('menu_categories', 'id')->where('tenant_id', $tenantId)],
            'price' => 'sometimes|integer|min:0',
            'cost' => 'sometimes|integer|min:0',
            'emoji' => 'nullable|string|max:10',
            'description' => 'nullable|string',
            'is_available' => 'sometimes|boolean',
            'recipe' => 'nullable|array',
            'recipe.*.ingredient_id' => ['required_with:recipe', Rule::exists('ingredients', 'id')->where('tenant_id', $tenantId)],
            'recipe.*.quantity' => 'required_with:recipe|numeric|min:0.0001',
        ]);

        $menuItem->update(collect($data)->except('recipe')->toArray());

        if (array_key_exists('recipe', $data)) {
            $menuItem->recipes()->delete();
            foreach ($data['recipe'] ?? [] as $r) {
                $menuItem->recipes()->create($r);
            }
        }

        return new MenuItemResource($menuItem->fresh()->load(['category', 'recipes.ingredient']));
    }

    public function destroy(MenuItem $menuItem)
    {
        $menuItem->delete();
        return response()->json(['message' => 'Menu item dihapus.']);
    }
}
