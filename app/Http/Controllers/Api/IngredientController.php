<?php

namespace App\Http\Controllers\Api;

use App\Enums\IngredientUnit;
use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IngredientController extends Controller
{
    public function index()
    {
        $ingredients = Ingredient::orderBy('name')->get()->map(fn ($i) => array_merge($i->toArray(), ['is_low_stock' => $i->isLowStock()]));
        return response()->json(['data' => $ingredients]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'unit' => ['required', Rule::in(array_column(IngredientUnit::cases(), 'value'))],
            'stock' => 'numeric|min:0',
            'min_stock' => 'numeric|min:0',
            'unit_cost' => 'integer|min:0',
        ]);
        return response()->json(['data' => Ingredient::create($data)], 201);
    }

    public function show(Ingredient $ingredient)
    {
        return response()->json(['data' => array_merge($ingredient->toArray(), ['is_low_stock' => $ingredient->isLowStock()])]);
    }

    public function update(Request $request, Ingredient $ingredient)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'unit' => ['sometimes', Rule::in(array_column(IngredientUnit::cases(), 'value'))],
            'min_stock' => 'sometimes|numeric|min:0',
            'unit_cost' => 'sometimes|integer|min:0',
        ]);
        $ingredient->update($data);
        return response()->json(['data' => $ingredient->fresh()]);
    }

    public function destroy(Ingredient $ingredient)
    {
        $ingredient->delete();
        return response()->json(['message' => 'Bahan dihapus.']);
    }

    public function lowStock()
    {
        $ingredients = Ingredient::whereColumn('stock', '<=', 'min_stock')->get();
        return response()->json(['data' => $ingredients]);
    }
}
