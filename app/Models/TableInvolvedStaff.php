<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;

class TableInvolvedStaff extends Model
{
    use TenantScoped;

    protected $table = 'table_involved_staff';
    protected $fillable = ['tenant_id', 'table_id', 'staff_id', 'staff_name'];
}
