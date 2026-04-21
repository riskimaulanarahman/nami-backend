<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessSettings;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show()
    {
        $settings = BusinessSettings::first();
        if (!$settings) $settings = BusinessSettings::create([]);
        return response()->json(['data' => $settings]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'address' => 'sometimes|string',
            'phone' => 'sometimes|string|max:30',
            'tax_percent' => 'sometimes|integer|min:0|max:100',
            'paper_size' => 'sometimes|in:58mm,80mm',
            'footer_message' => 'sometimes|string',
            'receipt_show_tax_line' => 'sometimes|boolean',
            'receipt_show_cashier' => 'sometimes|boolean',
            'receipt_show_payment_info' => 'sometimes|boolean',
            'receipt_show_member_info' => 'sometimes|boolean',
            'receipt_show_print_time' => 'sometimes|boolean',
            'printer_settings' => 'sometimes|array',
            'printer_settings.cashier' => 'sometimes|array',
            'printer_settings.cashier.enabled' => 'sometimes|boolean',
            'printer_settings.cashier.paperSize' => 'sometimes|in:58mm,80mm',
            'printer_settings.kitchen' => 'sometimes|array',
            'printer_settings.kitchen.enabled' => 'sometimes|boolean',
            'printer_settings.kitchen.paperSize' => 'sometimes|in:58mm,80mm',
        ]);

        $settings = BusinessSettings::first() ?? BusinessSettings::create([]);
        $settings->update($data);

        return response()->json(['data' => $settings->fresh()]);
    }
}
