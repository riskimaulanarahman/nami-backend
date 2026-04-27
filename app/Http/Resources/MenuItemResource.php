<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('recipes.ingredient');

        [$stockStatus, $availablePortions] = $this->computeStockStatus();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => $this->price,
            'cost' => $this->resource->effectiveCost(),
            'description' => $this->description,
            'is_available' => (bool)$this->is_available,
            'stock_status' => $stockStatus,
            'available_portions' => $availablePortions,
            'category' => $this->whenLoaded('category'),
            'recipes' => $this->whenLoaded('recipes'),
        ];
    }

    private function computeStockStatus(): array
    {
        if ($this->resource->recipes->isEmpty()) {
            return ['available', null];
        }

        $minPortions = PHP_INT_MAX;
        $outOfStock = false;
        $hasLowStock = false;

        foreach ($this->resource->recipes as $recipe) {
            $ingredient = $recipe->ingredient;
            if (!$ingredient || !$ingredient->is_active) continue;

            if ($recipe->quantity > 0) {
                $portions = (int) floor($ingredient->stock / $recipe->quantity);
                $minPortions = min($minPortions, $portions);
                if ($portions <= 0) {
                    $outOfStock = true;
                }
            }

            if ($ingredient->isLowStock()) {
                $hasLowStock = true;
            }
        }

        $availablePortions = $minPortions === PHP_INT_MAX ? null : $minPortions;

        if ($outOfStock) {
            return ['out_of_stock', 0];
        }

        if ($hasLowStock) {
            return ['low_stock', $availablePortions];
        }

        return ['available', $availablePortions];
    }
}
