<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentOption;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentOptionController extends Controller
{
    public function index()
    {
        return response()->json(['data' => PaymentOption::with('children')->whereNull('parent_id')->orderBy('sort_order')->get()]);
    }

    public function store(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:cash,qris,transfer',
            'icon' => 'nullable|string|max:10',
            'is_active' => 'boolean',
            'requires_reference' => 'boolean',
            'reference_label' => 'nullable|string',
            'parent_id' => ['nullable', Rule::exists('payment_options', 'id')->where('tenant_id', $tenantId)],
            'is_group' => 'boolean',
        ]);
        return response()->json(['data' => PaymentOption::create($data)], 201);
    }

    public function update(Request $request, PaymentOption $paymentOption)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:cash,qris,transfer',
            'icon' => 'nullable|string|max:10',
            'is_active' => 'sometimes|boolean',
            'requires_reference' => 'sometimes|boolean',
            'reference_label' => 'nullable|string',
        ]);
        $paymentOption->update($data);
        return response()->json(['data' => $paymentOption->fresh()]);
    }

    public function destroy(PaymentOption $paymentOption)
    {
        $paymentOption->delete();
        return response()->json(['message' => 'Metode pembayaran dihapus.']);
    }
}
