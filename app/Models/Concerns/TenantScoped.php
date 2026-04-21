<?php

namespace App\Models\Concerns;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;

trait TenantScoped
{
    public static function bootTenantScoped(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $tenantId = app(TenantContext::class)->tenantId();
            if ($tenantId) {
                $builder->where($builder->getModel()->getTable() . '.tenant_id', $tenantId);
            }
        });

        static::creating(function ($model): void {
            if (!empty($model->tenant_id)) {
                return;
            }

            $tenantId = app(TenantContext::class)->tenantId();
            if ($tenantId) {
                $model->tenant_id = $tenantId;
            }
        });
    }
}

