<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;

class CashierShiftInvolvedStaff extends Model
{
    use TenantScoped;

    protected $table = 'cashier_shift_involved_staff';
    protected $fillable = ['tenant_id', 'cashier_shift_id', 'staff_id', 'staff_name'];
}
