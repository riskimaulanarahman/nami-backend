<?php

namespace Database\Seeders;

use App\Models\BilliardPackage;
use App\Models\BusinessSettings;
use App\Models\Ingredient;
use App\Models\Member;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemRecipe;
use App\Models\PaymentOption;
use App\Models\Staff;
use App\Models\Table;
use App\Models\TableLayoutPosition;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::create([
            'name' => 'Demo Billiard & Cafe',
            'email' => 'owner@example.com',
            'password' => 'password123',
            'plan' => 'free',
            'is_active' => true,
        ]);

        BusinessSettings::create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Billiard & Cafe',
            'address' => 'Jl. Contoh No. 123',
            'phone' => '0812-3456-7890',
            'tax_percent' => 0,
            'paper_size' => '58mm',
            'footer_message' => "Terima kasih atas kunjungan Anda!",
        ]);

        $admin = Staff::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'username' => 'admin',
            'pin' => '1234',
            'role' => 'admin',
            'avatar' => 'AD',
            'is_active' => true,
        ]);

        Staff::create([
            'tenant_id' => $tenant->id,
            'name' => 'Kasir 1',
            'username' => 'kasir1',
            'pin' => '0000',
            'role' => 'kasir',
            'avatar' => 'K1',
            'is_active' => true,
        ]);

        $tables = [
            ['name' => 'Meja 1', 'type' => 'standard', 'hourly_rate' => 25000],
            ['name' => 'Meja 2', 'type' => 'standard', 'hourly_rate' => 25000],
            ['name' => 'VIP 1', 'type' => 'vip', 'hourly_rate' => 50000],
        ];

        $layoutDefaults = [
            0 => ['x_percent' => 8, 'y_percent' => 14, 'width_percent' => 26],
            1 => ['x_percent' => 37, 'y_percent' => 14, 'width_percent' => 26],
            2 => ['x_percent' => 66, 'y_percent' => 14, 'width_percent' => 26],
        ];

        foreach ($tables as $i => $data) {
            $table = Table::create([
                'tenant_id' => $tenant->id,
                ...$data,
            ]);

            TableLayoutPosition::create([
                'tenant_id' => $tenant->id,
                'table_id' => $table->id,
                ...$layoutDefaults[$i],
            ]);
        }

        $catFood = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Makanan',
            'emoji' => '🍽️',
            'sort_order' => 1,
        ]);
        $catDrink = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Minuman',
            'emoji' => '🥤',
            'sort_order' => 2,
        ]);

        $rice = Ingredient::create([
            'tenant_id' => $tenant->id,
            'name' => 'Nasi',
            'unit' => 'porsi',
            'stock' => 100,
            'min_stock' => 10,
            'unit_cost' => 2000,
        ]);
        $egg = Ingredient::create([
            'tenant_id' => $tenant->id,
            'name' => 'Telur',
            'unit' => 'pcs',
            'stock' => 100,
            'min_stock' => 10,
            'unit_cost' => 2500,
        ]);
        $coffee = Ingredient::create([
            'tenant_id' => $tenant->id,
            'name' => 'Kopi Bubuk',
            'unit' => 'gram',
            'stock' => 500,
            'min_stock' => 50,
            'unit_cost' => 50,
        ]);

        $nasiGoreng = MenuItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Nasi Goreng',
            'category_id' => $catFood->id,
            'legacy_category' => 'food',
            'price' => 20000,
            'cost' => 7500,
            'emoji' => '🍛',
            'description' => 'Nasi goreng spesial',
            'sort_order' => 1,
        ]);
        $esKopi = MenuItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Es Kopi',
            'category_id' => $catDrink->id,
            'legacy_category' => 'drink',
            'price' => 15000,
            'cost' => 3000,
            'emoji' => '☕',
            'description' => 'Es kopi susu',
            'sort_order' => 2,
        ]);

        MenuItemRecipe::create([
            'tenant_id' => $tenant->id,
            'menu_item_id' => $nasiGoreng->id,
            'ingredient_id' => $rice->id,
            'quantity' => 1,
        ]);
        MenuItemRecipe::create([
            'tenant_id' => $tenant->id,
            'menu_item_id' => $nasiGoreng->id,
            'ingredient_id' => $egg->id,
            'quantity' => 1,
        ]);
        MenuItemRecipe::create([
            'tenant_id' => $tenant->id,
            'menu_item_id' => $esKopi->id,
            'ingredient_id' => $coffee->id,
            'quantity' => 15,
        ]);

        PaymentOption::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cash',
            'type' => 'cash',
            'icon' => '💵',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        PaymentOption::create([
            'tenant_id' => $tenant->id,
            'name' => 'QRIS',
            'type' => 'qris',
            'icon' => '📱',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        BilliardPackage::create([
            'tenant_id' => $tenant->id,
            'name' => 'Paket 1 Jam',
            'duration_hours' => 1,
            'price' => 25000,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        BilliardPackage::create([
            'tenant_id' => $tenant->id,
            'name' => 'Paket 2 Jam',
            'duration_hours' => 2,
            'price' => 45000,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        Member::create([
            'tenant_id' => $tenant->id,
            'code' => 'MBR-001',
            'name' => 'Andi',
            'phone' => '0812-1111-2222',
            'tier' => 'Bronze',
            'points_balance' => 80,
        ]);
    }
}

