<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentOption;
use App\Services\PaymentOptionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentOptionController extends Controller
{
    public function __construct(private PaymentOptionService $paymentOptionService) {}

    public function index()
    {
        $tenantId = request()->user()?->tenant_id;
        $this->paymentOptionService->ensureSystemDefaultsForTenant($tenantId);

        return response()->json([
            'data' => $this->paymentOptionService
                ->systemDefaultsTree($tenantId)
                ->map(fn (PaymentOption $option) => $this->serializeOption($option))
                ->values(),
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;
        $this->paymentOptionService->ensureSystemDefaultsForTenant($tenantId);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'icon' => 'nullable|string|max:10',
            'is_active' => 'sometimes|boolean',
            'requires_reference' => 'sometimes|boolean',
            'reference_label' => 'nullable|string|max:100',
            'parent_id' => ['required', Rule::exists('payment_options', 'id')->where('tenant_id', $tenantId)],
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        if (($data['requires_reference'] ?? false) && empty($data['reference_label'])) {
            throw ValidationException::withMessages([
                'reference_label' => 'Label referensi wajib diisi jika metode membutuhkan referensi.',
            ]);
        }

        $payload = $this->paymentOptionService->validateChildPayload($data, $tenantId);

        return response()->json(['data' => $this->serializeOption(PaymentOption::create($payload))], 201);
    }

    public function update(Request $request, PaymentOption $paymentOption)
    {
        $tenantId = $request->user()?->tenant_id;
        $this->paymentOptionService->ensureSystemDefaultsForTenant($tenantId);
        $this->paymentOptionService->guardSystemDefaultMutation($paymentOption);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'icon' => 'nullable|string|max:10',
            'is_active' => 'sometimes|boolean',
            'requires_reference' => 'sometimes|boolean',
            'reference_label' => 'nullable|string|max:100',
            'parent_id' => ['nullable', Rule::exists('payment_options', 'id')->where('tenant_id', $tenantId)],
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        if (($data['requires_reference'] ?? $paymentOption->requires_reference) &&
            array_key_exists('reference_label', $data) &&
            empty($data['reference_label'])) {
            throw ValidationException::withMessages([
                'reference_label' => 'Label referensi wajib diisi jika metode membutuhkan referensi.',
            ]);
        }

        if (($data['parent_id'] ?? $paymentOption->parent_id) === $paymentOption->id) {
            throw ValidationException::withMessages([
                'parent_id' => 'Metode pembayaran tidak boleh menjadi parent dirinya sendiri.',
            ]);
        }

        if (!empty($data['parent_id'])) {
            $validated = $this->paymentOptionService->validateChildPayload([
                ...$paymentOption->toArray(),
                ...$data,
            ], $tenantId);
            $paymentOption->update($validated);
        } else {
            $paymentOption->update([
                'name' => $data['name'] ?? $paymentOption->name,
                'icon' => array_key_exists('icon', $data)
                    ? ((string) ($data['icon'] ?? ''))
                    : $paymentOption->icon,
                'is_active' => $data['is_active'] ?? $paymentOption->is_active,
                'requires_reference' => $data['requires_reference'] ?? $paymentOption->requires_reference,
                'reference_label' => $data['reference_label'] ?? $paymentOption->reference_label,
                'parent_id' => $paymentOption->parent_id,
                'type' => $paymentOption->type->value,
                'is_group' => false,
            ]);
        }

        return response()->json(['data' => $this->serializeOption($paymentOption->fresh())]);
    }

    public function destroy(PaymentOption $paymentOption)
    {
        $this->paymentOptionService->guardSystemDefaultMutation($paymentOption);
        $paymentOption->delete();
        return response()->json(['message' => 'Metode pembayaran dihapus.']);
    }

    private function serializeOption(PaymentOption $option): array
    {
        return [
            'id' => $option->id,
            'name' => $option->name,
            'type' => $option->type->value,
            'icon' => $option->icon,
            'is_active' => $option->is_active,
            'requires_reference' => $option->requires_reference,
            'reference_label' => $option->reference_label,
            'parent_id' => $option->parent_id,
            'is_group' => $option->is_group,
            'is_system_default' => $option->is_system_default,
            'sort_order' => $option->sort_order,
            'children' => $option->relationLoaded('children')
                ? $option->children
                    ->map(fn (PaymentOption $child) => $this->serializeOption($child))
                    ->values()
                    ->all()
                : [],
        ];
    }
}
