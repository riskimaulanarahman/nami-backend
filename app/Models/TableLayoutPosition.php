<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;

class TableLayoutPosition extends Model
{
    use TenantScoped;

    protected $primaryKey = 'table_id';
    public $incrementing = false;

    protected $fillable = ['tenant_id', 'table_id', 'x_percent', 'y_percent', 'width_percent'];

    protected function casts(): array
    {
        return [
            'x_percent' => 'float',
            'y_percent' => 'float',
            'width_percent' => 'float',
        ];
    }

    public function table()
    {
        return $this->belongsTo(Table::class);
    }
}
