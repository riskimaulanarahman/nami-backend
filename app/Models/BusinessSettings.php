<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class BusinessSettings extends Model
{
    use TenantScoped;
    protected $fillable = [
        'tenant_id',
        'name', 'address', 'phone', 'tax_percent', 'paper_size', 'footer_message',
        'receipt_show_tax_line', 'receipt_show_cashier', 'receipt_show_payment_info',
        'receipt_show_member_info', 'receipt_show_print_time',
        'printer_settings',
    ];

    protected function casts(): array
    {
        return [
            'tax_percent' => 'integer',
            'receipt_show_tax_line' => 'boolean',
            'receipt_show_cashier' => 'boolean',
            'receipt_show_payment_info' => 'boolean',
            'receipt_show_member_info' => 'boolean',
            'receipt_show_print_time' => 'boolean',
            'printer_settings' => 'array',
        ];
    }
}
