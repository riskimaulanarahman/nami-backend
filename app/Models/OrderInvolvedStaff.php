<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;

class OrderInvolvedStaff extends Model
{
    use TenantScoped;

    protected $table = 'order_involved_staff';
    public $timestamps = false;
    protected $fillable = ['tenant_id', 'order_id', 'staff_id', 'staff_name'];
}
