<?php

namespace App\Services;

use App\Models\PaymentOption;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentOptionService
{
    public function ensureSystemDefaultsForTenant(string $tenantId): Collection
    {
        $defaults = collect();

        foreach (PaymentOption::SYSTEM_DEFAULTS as $type => $definition) {
            $option = PaymentOption::withoutGlobalScope('tenant')
                ->withTrashed()
                ->firstOrNew([
                    'tenant_id' => $tenantId,
                    'type' => $type,
                    'is_system_default' => true,
                ]);

            $option->fill([
                'name' => $definition['name'],
                'icon' => $definition['icon'],
                'is_active' => true,
                'requires_reference' => false,
                'reference_label' => '',
                'parent_id' => null,
                'is_group' => false,
                'sort_order' => $definition['sort_order'],
                'is_system_default' => true,
            ]);

            if ($option->trashed()) {
                $option->restore();
            }

            $option->save();
            $defaults->push($option->fresh());
        }

        return $defaults->sortBy('sort_order')->values();
    }

    public function disableLegacyTopLevelOptionsForTenant(string $tenantId): int
    {
        return PaymentOption::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereNull('parent_id')
            ->where('is_system_default', false)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    public function systemDefaultsTree(string $tenantId): Collection
    {
        $this->ensureSystemDefaultsForTenant($tenantId);

        return PaymentOption::withoutGlobalScope('tenant')
            ->with([
                'children' => fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->orderBy('sort_order')
                    ->orderBy('name'),
            ])
            ->where('tenant_id', $tenantId)
            ->where('is_system_default', true)
            ->orderBy('sort_order')
            ->get();
    }

    public function validateChildPayload(array $data, string $tenantId): array
    {
        $parentId = $data['parent_id'] ?? null;
        if (!$parentId) {
            throw ValidationException::withMessages([
                'parent_id' => 'Sub metode pembayaran harus memilih parent default.',
            ]);
        }

        $parent = PaymentOption::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('id', $parentId)
            ->where('is_system_default', true)
            ->first();

        if (!$parent) {
            throw ValidationException::withMessages([
                'parent_id' => 'Parent metode pembayaran harus default sistem.',
            ]);
        }

        return [
            'name' => trim((string) ($data['name'] ?? '')),
            'type' => $parent->type->value,
            'icon' => isset($data['icon']) ? (string) $data['icon'] : '',
            'is_active' => (bool) ($data['is_active'] ?? true),
            'requires_reference' => (bool) ($data['requires_reference'] ?? false),
            'reference_label' => (string) ($data['reference_label'] ?? ''),
            'parent_id' => $parent->id,
            'is_group' => false,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_system_default' => false,
        ];
    }

    public function guardSystemDefaultMutation(PaymentOption $paymentOption): void
    {
        if (!$paymentOption->is_system_default) {
            return;
        }

        throw ValidationException::withMessages([
            'payment_option' => 'Metode pembayaran default sistem tidak dapat diubah atau dihapus.',
        ]);
    }

    public function resolvePaymentMethodDisplayName(?string $paymentMethodId, ?string $fallback = null): ?string
    {
        if (!$paymentMethodId) {
            return $fallback;
        }

        $option = PaymentOption::withoutGlobalScope('tenant')
            ->withTrashed()
            ->find($paymentMethodId);

        if (!$option) {
            return $fallback;
        }

        if (!$option->parent_id) {
            return $option->name;
        }

        $parent = PaymentOption::withoutGlobalScope('tenant')
            ->withTrashed()
            ->find($option->parent_id);

        if (!$parent) {
            return $fallback ?: $option->name;
        }

        return "{$parent->name} - {$option->name}";
    }

    public function summarizeTransactionsByPaymentMethod($orders, string $tenantId): array
    {
        $defaults = $this->systemDefaultsTree($tenantId)->keyBy('id');

        $summary = $defaults->map(function (PaymentOption $parent) {
            return [
                'parent_id' => $parent->id,
                'parent_name' => $parent->name,
                'parent_type' => $parent->type->value,
                'transaction_count' => 0,
                'gross_revenue' => 0,
                'net_revenue' => 0,
                'children' => [],
            ];
        })->all();

        foreach ($orders as $order) {
            [$parent, $childKey, $childLabel] = $this->resolveReportBucket($order, $defaults);
            if ($parent === null) {
                continue;
            }

            $parentEntry = &$summary[$parent->id];
            $amount = (int) $order->grand_total;
            $isCompleted = ($order->status?->value ?? $order->status) === 'completed';
            $netDelta = $isCompleted ? $amount : -$amount;

            $parentEntry['transaction_count']++;
            $parentEntry['gross_revenue'] += $amount;
            $parentEntry['net_revenue'] += $netDelta;

            if (!isset($parentEntry['children'][$childKey])) {
                $parentEntry['children'][$childKey] = [
                    'child_id' => $childKey === 'direct' ? null : $childKey,
                    'child_name' => $childLabel,
                    'transaction_count' => 0,
                    'gross_revenue' => 0,
                    'net_revenue' => 0,
                ];
            }

            $parentEntry['children'][$childKey]['transaction_count']++;
            $parentEntry['children'][$childKey]['gross_revenue'] += $amount;
            $parentEntry['children'][$childKey]['net_revenue'] += $netDelta;
        }

        return array_values(array_map(function (array $entry) {
            $entry['children'] = array_values($entry['children']);
            usort($entry['children'], fn (array $a, array $b) => strcmp($a['child_name'], $b['child_name']));
            return $entry;
        }, $summary));
    }

    private function resolveReportBucket($order, EloquentCollection $defaults): array
    {
        $option = null;
        if (!empty($order->payment_method_id)) {
            $option = PaymentOption::withoutGlobalScope('tenant')
                ->withTrashed()
                ->find($order->payment_method_id);
        }

        if ($option?->is_system_default) {
            return [$option, 'direct', 'Direct'];
        }

        if ($option && $option->parent_id) {
            $parent = PaymentOption::withoutGlobalScope('tenant')
                ->withTrashed()
                ->find($option->parent_id);

            if ($parent?->is_system_default && isset($defaults[$parent->id])) {
                return [$parent, $option->id, $option->name];
            }
        }

        $fallbackType = $this->inferParentType($order);
        if (!$fallbackType) {
            return [null, null, null];
        }

        $parent = $defaults->first(fn (PaymentOption $candidate) => $candidate->type->value === $fallbackType);
        if (!$parent) {
            return [null, null, null];
        }

        return [$parent, 'direct', 'Direct'];
    }

    private function inferParentType($order): ?string
    {
        $name = strtolower((string) ($order->payment_method_name ?? ''));
        if (str_starts_with($name, 'qris')) {
            return 'qris';
        }
        if (str_starts_with($name, 'transfer')) {
            return 'transfer';
        }

        $type = $order->payment_method_type?->value ?? $order->payment_method_type;
        if ($type === 'cash') {
            return 'cash';
        }
        if ($type === 'non-cash') {
            return $name !== '' ? 'transfer' : null;
        }

        return null;
    }
}
