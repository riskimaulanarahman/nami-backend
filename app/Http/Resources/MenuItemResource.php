<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('recipes.ingredient');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => $this->price,
            'cost' => $this->resource->effectiveCost(),
            'description' => $this->description,
            'is_available' => (bool)$this->is_available,
            'category' => $this->whenLoaded('category'),
            'recipes' => $this->whenLoaded('recipes'),
        ];
    }
}
