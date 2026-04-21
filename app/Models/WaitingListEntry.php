<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;

use App\Enums\WaitingListStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;


class WaitingListEntry extends Model
{
    use TenantScoped;
    use HasUlids;

    protected $table = 'waiting_list_entries';

    protected $fillable = [
        'tenant_id',
        'customer_name', 'phone', 'party_size', 'notes',
        'preferred_table_type', 'status', 'seated_at', 'table_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => WaitingListStatus::class,
            'party_size' => 'integer',
            'seated_at' => 'datetime',
        ];
    }

    public function table() { return $this->belongsTo(Table::class); }
}
