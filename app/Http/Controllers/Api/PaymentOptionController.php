<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentOption;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentOptionController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => PaymentOption::with('children')
                ->whereNull('parent_id')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:cash,qris,transfer',
            'icon' => 'nullable|string|max:10',
            'is_active' => 'sometimes|boolean',
            'requires_reference' => 'sometimes|boolean',
            'reference_label' => 'nullable|string|max:100',
            'parent_id' => ['nullable', Rule::exists('payment_options', 'id')->where('tenant_id', $tenantId)],
            'is_group' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        if (($data['requires_reference'] ?? false) && empty($data['reference_label'])) {
            throw ValidationException::withMessages([
                'reference_label' => 'Label referensi wajib diisi jika metode membutuhkan referensi.',
            ]);
        }

        return response()->json(['data' => PaymentOption::create($data)], 201);
    }

    public function update(Request $request, PaymentOption $paymentOption)
    {
        $tenantId = $request->user()?->tenant_id;

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:cash,qris,transfer',
            'icon' => 'nullable|string|max:10',
            'is_active' => 'sometimes|boolean',
            'requires_reference' => 'sometimes|boolean',
            'reference_label' => 'nullable|string|max:100',
            'parent_id' => ['nullable', Rule::exists('payment_options', 'id')->where('tenant_id', $tenantId)],
            'is_group' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        if (($data['requires_reference'] ?? $paymentOption->requires_reference) &&
            array_key_exists('reference_label', $data) &&
            empty($data['reference_label'])) {
            throw ValidationException::withMessages([
                'reference_label' => 'Label referensi wajib diisi jika metode membutuhkan referensi.',
            ]);
        }

        if (($data['parent_id'] ?? null) === $paymentOption->id) {
            throw ValidationException::withMessages([
                'parent_id' => 'Metode pembayaran tidak boleh menjadi parent dirinya sendiri.',
            ]);
        }

        $paymentOption->update($data);
        return response()->json(['data' => $paymentOption->fresh()]);
    }

    public function destroy(PaymentOption $paymentOption)
    {
        $paymentOption->delete();
        return response()->json(['message' => 'Metode pembayaran dihapus.']);
    }
}
