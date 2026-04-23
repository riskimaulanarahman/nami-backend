<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MenuItemResource;
use App\Models\MenuItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $data = $request->validate($this->rules($tenantId), $this->messages());

        $menuItem = DB::transaction(function () use ($data) {
            $menuItem = MenuItem::create(collect($data)->except(['recipe', 'cost'])->toArray());

            foreach ($data['recipe'] ?? [] as $recipe) {
                $menuItem->recipes()->create($recipe);
            }

            return $menuItem;
        });

        return new MenuItemResource($menuItem->fresh()->load(['category', 'recipes.ingredient']));
    }

    public function show(MenuItem $menuItem)
    {
        return new MenuItemResource($menuItem->load(['category', 'recipes.ingredient']));
    }

    public function update(Request $request, MenuItem $menuItem)
    {
        $tenantId = $request->user()?->tenant_id;

        $data = $request->validate($this->rules($tenantId, true), $this->messages());

        DB::transaction(function () use ($data, $menuItem) {
            $menuItem->update(collect($data)->except(['recipe', 'cost'])->toArray());

            if (array_key_exists('recipe', $data)) {
                $menuItem->recipes()->delete();
                foreach ($data['recipe'] ?? [] as $recipe) {
                    $menuItem->recipes()->create($recipe);
                }
            }
        });

        return new MenuItemResource($menuItem->fresh()->load(['category', 'recipes.ingredient']));
    }

    public function destroy(MenuItem $menuItem)
    {
        $menuItem->delete();
        return response()->json(['message' => 'Menu item dihapus.']);
    }

    private function rules(?string $tenantId, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'category_id' => [$required, Rule::exists('menu_categories', 'id')->where('tenant_id', $tenantId)],
            'price' => [$required, 'integer', 'min:0'],
            'cost' => 'integer|min:0',
            'description' => 'nullable|string',
            'is_available' => $partial ? 'sometimes|boolean' : 'boolean',
            'recipe' => 'nullable|array',
            'recipe.*.ingredient_id' => [
                'required_with:recipe',
                'distinct',
                Rule::exists('ingredients', 'id')->where('tenant_id', $tenantId),
            ],
            'recipe.*.quantity' => 'required_with:recipe|numeric|min:0.0001',
        ];
    }

    private function messages(): array
    {
        return [
            'recipe.*.ingredient_id.distinct' => 'Bahan pada recipe tidak boleh duplikat.',
        ];
    }
}
