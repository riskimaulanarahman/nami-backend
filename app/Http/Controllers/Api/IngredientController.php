<?php

namespace App\Http\Controllers\Api;

use App\Enums\IngredientUnit;
use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IngredientController extends Controller
{
    public function index(Request $request)
    {
        $requestStatus = $request->query('status', 'active');
        if (!in_array($requestStatus, ['active', 'archived', 'all'], true)) {
            return response()->json(['message' => 'Filter status ingredient tidak valid.'], 422);
        }

        $status = $requestStatus;

        $ingredients = Ingredient::query()
            ->withCount([
                'recipes as recipe_usage_count' => fn (Builder $query) => $query->whereHas('menuItem'),
                'adjustments as stock_adjustment_count',
            ])
            ->when($status === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($status === 'archived', fn (Builder $query) => $query->where('is_active', false))
            ->orderBy('name')
            ->get()
            ->map(fn (Ingredient $ingredient) => $this->serializeIngredient($ingredient));

        return response()->json(['data' => $ingredients]);
    }

    public function store(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'unit' => ['required', Rule::in(array_column(IngredientUnit::cases(), 'value'))],
            'stock' => 'sometimes|numeric|min:0',
            'min_stock' => 'sometimes|numeric|min:0',
            'unit_cost' => 'sometimes|integer|min:0',
        ]);

        $this->ensureNameAvailable($tenantId, $data['name']);

        $ingredient = Ingredient::create([
            'name' => trim($data['name']),
            'unit' => $data['unit'],
            'stock' => $data['stock'] ?? 0,
            'min_stock' => $data['min_stock'] ?? 0,
            'unit_cost' => $data['unit_cost'] ?? 0,
            'is_active' => true,
        ]);

        $ingredient->loadCount([
            'recipes as recipe_usage_count' => fn (Builder $query) => $query->whereHas('menuItem'),
            'adjustments as stock_adjustment_count',
        ]);

        return response()->json(['data' => $this->serializeIngredient($ingredient)], 201);
    }

    public function show(Ingredient $ingredient)
    {
        $ingredient->loadCount([
            'recipes as recipe_usage_count' => fn (Builder $query) => $query->whereHas('menuItem'),
            'adjustments as stock_adjustment_count',
        ]);

        return response()->json(['data' => $this->serializeIngredient($ingredient)]);
    }

    public function update(Request $request, Ingredient $ingredient)
    {
        $tenantId = $request->user()?->tenant_id;

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'unit' => ['sometimes', Rule::in(array_column(IngredientUnit::cases(), 'value'))],
            'stock' => 'sometimes|numeric|min:0',
            'min_stock' => 'sometimes|numeric|min:0',
            'unit_cost' => 'sometimes|integer|min:0',
        ]);

        if (array_key_exists('name', $data)) {
            $this->ensureNameAvailable($tenantId, $data['name'], $ingredient->id);
            $data['name'] = trim($data['name']);
        }

        $ingredient->update($data);
        $ingredient = $ingredient->fresh();
        $ingredient->loadCount([
            'recipes as recipe_usage_count' => fn (Builder $query) => $query->whereHas('menuItem'),
            'adjustments as stock_adjustment_count',
        ]);

        return response()->json(['data' => $this->serializeIngredient($ingredient)]);
    }

    public function archive(Ingredient $ingredient)
    {
        if (!$ingredient->canArchive()) {
            throw ValidationException::withMessages([
                'ingredient' => 'Bahan baku ini tidak perlu diarsipkan. Gunakan hapus permanen bila tidak punya recipe maupun histori stok.',
            ]);
        }

        $ingredient->update(['is_active' => false]);
        $ingredient = $ingredient->fresh();
        $ingredient->loadCount([
            'recipes as recipe_usage_count' => fn (Builder $query) => $query->whereHas('menuItem'),
            'adjustments as stock_adjustment_count',
        ]);

        return response()->json([
            'message' => 'Bahan baku diarsipkan. Recipe lama tetap tersimpan, tetapi bahan ini tidak bisa dipakai untuk recipe baru.',
            'data' => $this->serializeIngredient($ingredient),
        ]);
    }

    public function restore(Ingredient $ingredient)
    {
        $ingredient->update(['is_active' => true]);
        $ingredient = $ingredient->fresh();
        $ingredient->loadCount([
            'recipes as recipe_usage_count' => fn (Builder $query) => $query->whereHas('menuItem'),
            'adjustments as stock_adjustment_count',
        ]);

        return response()->json([
            'message' => 'Bahan baku dipulihkan dan bisa dipakai kembali.',
            'data' => $this->serializeIngredient($ingredient),
        ]);
    }

    public function destroy(Ingredient $ingredient)
    {
        if (!$ingredient->canDelete()) {
            $message = $ingredient->activeRecipeUsageCount() > 0
                ? 'Bahan baku masih dipakai di recipe menu dan harus diarsipkan, bukan dihapus.'
                : 'Bahan baku punya histori stok dan tidak bisa dihapus permanen. Arsipkan bahan ini.';

            throw ValidationException::withMessages([
                'ingredient' => $message,
            ]);
        }

        $ingredient->forceDelete();

        return response()->json(['message' => 'Bahan dihapus permanen.']);
    }

    public function lowStock()
    {
        $ingredients = Ingredient::where('is_active', true)
            ->whereColumn('stock', '<=', 'min_stock')
            ->orderBy('name')
            ->get()
            ->map(fn (Ingredient $ingredient) => $this->serializeIngredient($ingredient));

        return response()->json(['data' => $ingredients]);
    }

    private function ensureNameAvailable(?string $tenantId, string $name, ?string $ignoreId = null): void
    {
        $normalizedName = trim($name);

        $existing = Ingredient::query()
            ->when($tenantId, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->when($ignoreId, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($normalizedName)])
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'name' => 'Nama bahan baku sudah digunakan.',
            ]);
        }
    }

    private function serializeIngredient(Ingredient $ingredient): array
    {
        $recipeUsageCount = (int) ($ingredient->recipe_usage_count ?? $ingredient->activeRecipeUsageCount());
        $stockAdjustmentCount = (int) ($ingredient->stock_adjustment_count ?? $ingredient->stockAdjustmentCount());
        $canDelete = $recipeUsageCount === 0 && $stockAdjustmentCount === 0;

        return array_merge($ingredient->toArray(), [
            'is_low_stock' => $ingredient->is_active && $ingredient->isLowStock(),
            'recipe_usage_count' => $recipeUsageCount,
            'stock_adjustment_count' => $stockAdjustmentCount,
            'can_archive' => $ingredient->is_active && !$canDelete,
            'can_delete' => $canDelete,
        ]);
    }
}
