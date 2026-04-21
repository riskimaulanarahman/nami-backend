<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\StockAdjustment;
use App\Events\StockLow;

class StockService
{
    /**
     * Deduct ingredient stock based on menu item recipe.
     */
    public function deductForMenuItem(MenuItem $menuItem, int $quantity = 1): void
    {
        $menuItem->loadMissing('recipes.ingredient');

        foreach ($menuItem->recipes as $recipe) {
            $ingredient = $recipe->ingredient;
            if (!$ingredient) continue;

            $deductAmount = $recipe->quantity * $quantity;
            $newStock = max(0, round($ingredient->stock - $deductAmount, 4));

            $ingredient->update(['stock' => $newStock]);

            if ($newStock <= $ingredient->min_stock) {
                event(new StockLow($ingredient));
            }
        }
    }

    /**
     * Manual stock adjustment.
     */
    public function adjust(
        Ingredient $ingredient,
        string $type,
        float $quantity,
        string $reason = '',
        string $adjustedBy = '',
    ): StockAdjustment {
        $previousStock = $ingredient->stock;

        $newStock = match ($type) {
            'in' => $previousStock + $quantity,
            'out' => max(0, $previousStock - $quantity),
            'adjustment' => $quantity, // absolute set
            default => $previousStock,
        };

        $newStock = round($newStock, 4);

        $adjustment = StockAdjustment::create([
            'ingredient_id' => $ingredient->id,
            'type' => $type,
            'quantity' => $quantity,
            'reason' => $reason,
            'adjusted_by' => $adjustedBy,
            'previous_stock' => $previousStock,
            'new_stock' => $newStock,
        ]);

        $ingredient->update([
            'stock' => $newStock,
            'last_restocked_at' => $type === 'in' ? now() : $ingredient->last_restocked_at,
        ]);

        return $adjustment;
    }
}
