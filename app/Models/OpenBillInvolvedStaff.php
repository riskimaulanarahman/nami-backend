<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;

class OpenBillInvolvedStaff extends Model
{
    use TenantScoped;

    protected $table = 'open_bill_involved_staff';
    protected $fillable = ['tenant_id', 'open_bill_id', 'staff_id', 'staff_name'];
}
