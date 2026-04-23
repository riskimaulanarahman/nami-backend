<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\StockAdjustment;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockAdjustmentController extends Controller
{
    public function __construct(private StockService $stockService) {}

    public function index(Request $request)
    {
        $query = StockAdjustment::with('ingredient')->orderByDesc('created_at');
        if ($request->has('ingredient_id')) {
            $query->where('ingredient_id', $request->ingredient_id);
        }
        return response()->json(['data' => $query->paginate(50)]);
    }

    public function store(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;

        $data = $request->validate([
            'ingredient_id' => [
                'required',
                Rule::exists('ingredients', 'id')->where(function ($query) use ($tenantId) {
                    $query
                        ->where('tenant_id', $tenantId)
                        ->where('is_active', true)
                        ->whereNull('deleted_at');
                }),
            ],
            'type' => 'required|in:in,out,adjustment',
            'quantity' => 'required|numeric|min:0.0001',
            'reason' => 'nullable|string',
        ]);

        $ingredient = Ingredient::findOrFail($data['ingredient_id']);
        $adjustment = $this->stockService->adjust(
            $ingredient, $data['type'], $data['quantity'],
            $data['reason'] ?? '', $request->user()?->name ?? 'System'
        );

        return response()->json(['data' => $adjustment->load('ingredient')], 201);
    }
}
