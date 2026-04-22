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

    protected $fillable = [
        'tenant_id',
        'name', 'type', 'icon', 'is_active', 'requires_reference',
        'reference_label', 'parent_id', 'is_group', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'type' => PaymentOptionType::class,
            'is_active' => 'boolean',
            'requires_reference' => 'boolean',
            'is_group' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function parent() { return $this->belongsTo(self::class, 'parent_id'); }
    public function children() { return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name'); }
}
