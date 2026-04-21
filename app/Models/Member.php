<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;

use App\Enums\MemberTier;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\SoftDeletes;

class Member extends Model
{
    use TenantScoped;
    use HasUlids, SoftDeletes;

    protected $fillable = ['tenant_id', 'code', 'name', 'phone', 'tier', 'points_balance'];

    protected function casts(): array
    {
        return [
            'tier' => MemberTier::class,
            'points_balance' => 'integer',
        ];
    }

    public function pointLedger() { return $this->hasMany(MemberPointLedger::class); }

    public function refreshTier(): void
    {
        $this->update(['tier' => MemberTier::fromPoints($this->points_balance)]);
    }
}
