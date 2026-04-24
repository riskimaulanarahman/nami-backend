<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;

use App\Enums\PaymentOptionType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentOption extends Model
{
    use TenantScoped;
    use HasUlids, SoftDeletes;

    public const SYSTEM_DEFAULTS = [
        'cash' => [
            'name' => 'Cash',
            'icon' => '💵',
            'sort_order' => 1,
        ],
        'qris' => [
            'name' => 'QRIS',
            'icon' => '📱',
            'sort_order' => 2,
        ],
        'transfer' => [
            'name' => 'Transfer',
            'icon' => '🏦',
            'sort_order' => 3,
        ],
    ];

    protected $fillable = [
        'tenant_id',
        'name', 'type', 'icon', 'is_active', 'requires_reference',
        'reference_label', 'parent_id', 'is_group', 'sort_order',
        'is_system_default',
    ];

    protected function casts(): array
    {
        return [
            'type' => PaymentOptionType::class,
            'is_active' => 'boolean',
            'requires_reference' => 'boolean',
            'is_group' => 'boolean',
            'sort_order' => 'integer',
            'is_system_default' => 'boolean',
        ];
    }

    public function parent() { return $this->belongsTo(self::class, 'parent_id'); }
    public function children() { return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name'); }

    public static function systemDefaultDefinition(string $type): ?array
    {
        return self::SYSTEM_DEFAULTS[$type] ?? null;
    }

    public static function systemDefaultTypes(): array
    {
        return array_keys(self::SYSTEM_DEFAULTS);
    }
}
