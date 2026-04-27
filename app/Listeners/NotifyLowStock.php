<?php

namespace App\Listeners;

use App\Events\StockLow;
use Illuminate\Support\Facades\Log;

class NotifyLowStock
{
    public function handle(StockLow $event): void
    {
        $ingredient = $event->ingredient;
        Log::warning("STOK RENDAH: Bahan '{$ingredient->name}' tersisa {$ingredient->stock} {$ingredient->unit->value} (Batas: {$ingredient->min_stock}).");
        
        // In production, this would send a WhatsApp/Email/Telegram notification.
    }
}
