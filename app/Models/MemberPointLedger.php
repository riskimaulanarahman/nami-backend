<?php

namespace App\Models;

use App\Enums\PointLedgerType;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MemberPointLedger extends Model
{
    use TenantScoped;
    use HasUlids;

    protected $table = 'member_point_ledger';

    protected $fillable = ['tenant_id', 'member_id', 'order_id', 'type', 'points', 'amount', 'note'];

    protected function casts(): array
    {
        return [
            'type' => PointLedgerType::class,
            'points' => 'integer',
            'amount' => 'integer',
        ];
    }

    public function member() { return $this->belongsTo(Member::class); }
}
