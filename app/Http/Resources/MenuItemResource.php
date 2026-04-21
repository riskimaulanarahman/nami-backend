<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => $this->price,
            'cost' => $this->cost,
            'emoji' => $this->emoji,
            'description' => $this->description,
            'is_available' => (bool)$this->is_available,
            'category' => $this->whenLoaded('category'),
            'recipes' => $this->whenLoaded('recipes'),
        ];
    }
}
