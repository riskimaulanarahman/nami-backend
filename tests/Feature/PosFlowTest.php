<?php

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\MenuItemRecipe;
use App\Models\Member;
use App\Models\BilliardPackage;
use App\Enums\OpenBillStatus;
use App\Models\OpenBill;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\PaymentOption;
use App\Models\Staff;
use App\Models\StockAdjustment;
use App\Models\Table;
use App\Models\TableOrderItem;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosFlowTest extends TestCase
{
    use RefreshDatabase;

    private function createTenantWithAdmin(string $name, string $email, string $password, string $pin): array
    {
        $tenant = Tenant::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'plan' => 'free',
            'is_active' => true,
        ]);

        $admin = Staff::create([
            'tenant_id' => $tenant->id,
            'name' => "{$name} Admin",
            'username' => strtolower(str_replace(' ', '-', $name)) . '-admin',
            'pin' => $pin,
            'role' => 'admin',
            'avatar' => 'AD',
            'is_active' => true,
        ]);

        return [$tenant, $admin];
    }

    private function createCashier(Tenant $tenant, string $name, string $pin): Staff
    {
        return Staff::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'username' => strtolower(str_replace(' ', '-', $name)),
            'pin' => $pin,
            'role' => 'kasir',
            'avatar' => 'CS',
            'is_active' => true,
        ]);
    }

    private function loginAsStaff(Tenant $tenant, Staff $staff, string $tenantPassword, string $pin): string
    {
        $tenantLogin = $this->postJson('/api/auth/tenant-login', [
            'email' => $tenant->email,
            'password' => $tenantPassword,
        ])->assertOk();

        $tenantToken = $tenantLogin->json('data.token');

        $staffLogin = $this->postJson('/api/auth/staff-pin-login', [
            'staff_id' => $staff->id,
            'pin' => $pin,
        ], [
            'Authorization' => "Bearer {$tenantToken}",
        ])->assertOk();

        $staffToken = $staffLogin->json('data.token');
        $this->assertNotEmpty($staffToken);

        return $staffToken;
    }

    private function openShift(string $staffToken, int $openingCash = 100000): void
    {
        $this->postJson('/api/cashier-shifts/open', [
            'opening_cash' => $openingCash,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);
    }

    public function test_staff_token_cannot_access_other_tenant_table(): void
    {
        [$tenantA, $adminA] = $this->createTenantWithAdmin('Tenant A', 'a@example.com', 'password123', '1111');
        [$tenantB] = $this->createTenantWithAdmin('Tenant B', 'b@example.com', 'password123', '2222');

        Table::create([
            'tenant_id' => $tenantA->id,
            'name' => 'A-Table',
            'type' => 'standard',
            'hourly_rate' => 20000,
        ]);

        $tableB = Table::create([
            'tenant_id' => $tenantB->id,
            'name' => 'B-Table',
            'type' => 'standard',
            'hourly_rate' => 20000,
        ]);

        $staffTokenA = $this->loginAsStaff($tenantA, $adminA, 'password123', '1111');

        $tables = $this->getJson('/api/tables', [
            'Authorization' => "Bearer {$staffTokenA}",
        ])->assertOk();

        $this->assertCount(1, $tables->json('data'));
        $this->assertEquals('A-Table', $tables->json('data.0.name'));

        $this->getJson("/api/tables/{$tableB->id}", [
            'Authorization' => "Bearer {$staffTokenA}",
        ])->assertStatus(404);
    }

    public function test_complete_pos_flow_billiard_with_new_auth_chain(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant A', 'a@example.com', 'password123', '1234');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '1234');

        $table = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Table 1',
            'type' => 'standard',
            'hourly_rate' => 20000,
        ]);

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Food',
            'emoji' => '🍽️',
        ]);

        $menuItem = MenuItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Coffee',
            'category_id' => $category->id,
            'legacy_category' => 'drink',
            'price' => 10000,
            'cost' => 4000,
            'is_available' => true,
        ]);

        $payment = PaymentOption::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cash',
            'type' => 'cash',
            'is_active' => true,
        ]);

        // 1. Open shift
        $openShift = $this->postJson('/api/cashier-shifts/open', [
            'opening_cash' => 100000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $shiftId = $openShift->json('data.id');

        // 2. Start billiard session
        $this->postJson("/api/tables/{$table->id}/start-session", [
            'session_type' => 'billiard',
            'billing_mode' => 'open-bill',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        // 3. Add menu item
        $this->postJson("/api/tables/{$table->id}/add-order", [
            'menu_item_id' => $menuItem->id,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        // 4. Checkout
        $checkout = $this->postJson("/api/tables/{$table->id}/checkout", [
            'payment_method_id' => $payment->id,
            'payment_method_name' => 'Cash',
            'cash_received' => 50000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $checkout
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.payment_method_id', $payment->id)
            ->assertJsonPath('data.cash_received', 50000);

        $grandTotal = (int) $checkout->json('data.grand_total');
        $this->assertGreaterThanOrEqual(10000, $grandTotal);
        $this->assertSame(50000 - $grandTotal, (int) $checkout->json('data.change_amount'));

        // 5. Verify transaction is attached to active shift
        $transactions = $this->getJson("/api/cashier-shifts/{$shiftId}/transactions", [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $this->assertNotEmpty($transactions->json('data'));
    }

    public function test_open_bill_billiard_uses_minimum_hour_then_proportional_minutes_while_package_overrun_keeps_hourly_rounding(): void
    {
        $fixedNow = \Illuminate\Support\Carbon::parse('2026-04-25 12:00:00');
        \Illuminate\Support\Carbon::setTestNow($fixedNow);

        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Minute Billing', 'minute-billing@example.com', 'password123', '4545');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '4545');

        $payment = PaymentOption::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cash',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Snack',
            'emoji' => '🍟',
        ]);

        $menuItem = MenuItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Fries',
            'category_id' => $category->id,
            'legacy_category' => 'food',
            'price' => 25000,
            'cost' => 7000,
            'emoji' => '🍟',
            'is_available' => true,
        ]);

        $this->openShift($staffToken);

        $invalidTable = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Invalid Timer',
            'type' => 'standard',
            'hourly_rate' => 25000,
            'status' => 'occupied',
            'session_type' => 'billiard',
            'billing_mode' => 'open-bill',
        ]);

        $zeroMinuteTable = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Zero Minute',
            'type' => 'standard',
            'hourly_rate' => 25000,
            'status' => 'occupied',
            'start_time' => $fixedNow->copy(),
            'session_type' => 'billiard',
            'billing_mode' => 'open-bill',
        ]);

        $fiftyNineMinuteTable = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Fifty Nine',
            'type' => 'standard',
            'hourly_rate' => 25000,
            'status' => 'occupied',
            'start_time' => $fixedNow->copy()->subMinutes(59),
            'session_type' => 'billiard',
            'billing_mode' => 'open-bill',
        ]);

        $sixtyMinuteTable = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Sixty Minute',
            'type' => 'standard',
            'hourly_rate' => 25000,
            'status' => 'occupied',
            'start_time' => $fixedNow->copy()->subMinutes(60),
            'session_type' => 'billiard',
            'billing_mode' => 'open-bill',
        ]);

        $sixtyOneMinuteTable = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Sixty One',
            'type' => 'standard',
            'hourly_rate' => 25000,
            'status' => 'occupied',
            'start_time' => $fixedNow->copy()->subMinutes(61),
            'session_type' => 'billiard',
            'billing_mode' => 'open-bill',
        ]);

        $fourHourFifteenTable = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Four Fifteen',
            'type' => 'standard',
            'hourly_rate' => 25000,
            'status' => 'occupied',
            'start_time' => $fixedNow->copy()->subMinutes(255),
            'session_type' => 'billiard',
            'billing_mode' => 'open-bill',
        ]);

        $draftTable = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Draft Minute Billing',
            'type' => 'vip',
            'hourly_rate' => 40000,
            'status' => 'occupied',
            'start_time' => $fixedNow->copy()->subMinutes(80),
            'session_type' => 'billiard',
            'billing_mode' => 'open-bill',
        ]);

        TableOrderItem::create([
            'tenant_id' => $tenant->id,
            'table_id' => $draftTable->id,
            'menu_item_id' => $menuItem->id,
            'quantity' => 1,
            'unit_price' => 25000,
            'added_at' => $fixedNow->copy()->subMinutes(20),
        ]);

        $overrunTable = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Overrun Legacy',
            'type' => 'standard',
            'hourly_rate' => 25000,
            'status' => 'occupied',
            'start_time' => $fixedNow->copy()->subMinutes(90),
            'session_type' => 'billiard',
            'billing_mode' => 'open-bill',
            'selected_package_name' => 'Paket 1 Jam',
            'selected_package_hours' => 1,
            'selected_package_price' => 40000,
            'package_minutes_total' => 60,
            'package_total_price' => 40000,
            'package_expired_at' => $fixedNow->copy()->subMinutes(30),
            'overrun_started_at' => $fixedNow->copy()->subMinutes(30),
        ]);

        $this->getJson('/api/tables', [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonFragment([
                'id' => $sixtyOneMinuteTable->id,
                'elapsed_minutes' => 61,
                'billing_mode' => 'open-bill',
            ]);

        $this->getJson("/api/tables/{$sixtyOneMinuteTable->id}", [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.elapsed_minutes', 61)
            ->assertJsonPath('data.billing_mode', 'open-bill');

        $this->getJson("/api/tables/{$invalidTable->id}/bill", [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.rental_cost', 0)
            ->assertJsonPath('data.grand_total', 0);

        $this->getJson("/api/tables/{$zeroMinuteTable->id}/bill", [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.duration_minutes', 0)
            ->assertJsonPath('data.rental_cost', 25000);

        $this->getJson("/api/tables/{$fiftyNineMinuteTable->id}/bill", [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.duration_minutes', 59)
            ->assertJsonPath('data.rental_cost', 25000);

        $this->getJson("/api/tables/{$sixtyMinuteTable->id}/bill", [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.duration_minutes', 60)
            ->assertJsonPath('data.rental_cost', 25000);

        $this->getJson("/api/tables/{$sixtyOneMinuteTable->id}/bill", [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.duration_minutes', 61)
            ->assertJsonPath('data.rental_cost', 26000);

        $this->getJson("/api/tables/{$fourHourFifteenTable->id}/bill", [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.duration_minutes', 255)
            ->assertJsonPath('data.rental_cost', 107000)
            ->assertJsonPath('data.grand_total', 107000);

        $this->getJson("/api/tables/{$overrunTable->id}/bill", [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.duration_minutes', 90)
            ->assertJsonPath('data.active_overrun_minutes', 30)
            ->assertJsonPath('data.rental_cost', 65000)
            ->assertJsonPath('data.grand_total', 65000);

        $this->postJson("/api/tables/{$fourHourFifteenTable->id}/checkout", [
            'payment_method_id' => $payment->id,
            'payment_method_name' => 'Cash',
            'cash_received' => 120000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.rental_cost', 107000)
            ->assertJsonPath('data.grand_total', 107000)
            ->assertJsonPath('data.duration_minutes', 255);

        $draftResponse = $this->postJson("/api/tables/{$draftTable->id}/close-to-draft", [], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.session_charge_total', 54000);

        $storedDraft = OpenBill::findOrFail($draftResponse->json('data.id'));
        $this->assertSame(54000, (int) $storedDraft->session_charge_total);

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_open_bill_checkout_clamps_negative_duration_to_zero(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Cafe', 'cafe@example.com', 'password123', '4321');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '4321');

        $table = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Meja 1',
            'type' => 'standard',
            'hourly_rate' => 20000,
        ]);

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Drink',
            'emoji' => '🥤',
        ]);

        $menuItem = MenuItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Tea',
            'category_id' => $category->id,
            'legacy_category' => 'drink',
            'price' => 15000,
            'cost' => 3000,
            'is_available' => true,
        ]);

        $payment = PaymentOption::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cash',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $this->postJson('/api/cashier-shifts/open', [
            'opening_cash' => 100000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $createBill = $this->postJson('/api/open-bills', [
            'table_id' => $table->id,
            'customer_name' => 'Walk In',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $openBillId = $createBill->json('data.id');

        $this->postJson("/api/open-bills/{$openBillId}/add-item", [
            'fulfillment_type' => 'dine-in',
            'menu_item_id' => $menuItem->id,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        OpenBill::findOrFail($openBillId)->forceFill([
            'created_at' => now()->addMinutes(100),
            'updated_at' => now()->addMinutes(100),
        ])->save();

        $checkout = $this->postJson("/api/open-bills/{$openBillId}/checkout", [
            'payment_method_id' => $payment->id,
            'payment_method_name' => 'Cash',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $checkout
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.duration_minutes', 0);

        $grandTotal = (int) $checkout->json('data.grand_total');
        $this->assertSame($grandTotal, (int) $checkout->json('data.cash_received'));
        $this->assertSame(0, (int) $checkout->json('data.change_amount'));
    }

    public function test_open_bill_cash_checkout_rejects_cash_received_below_total(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Cash Guard', 'cash-guard@example.com', 'password123', '6789');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '6789');

        $table = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Meja Cash',
            'type' => 'standard',
            'hourly_rate' => 20000,
        ]);

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Drink',
            'emoji' => '🥤',
        ]);

        $menuItem = MenuItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Lemon Tea',
            'category_id' => $category->id,
            'legacy_category' => 'drink',
            'price' => 15000,
            'cost' => 4000,
            'is_available' => true,
        ]);

        $payment = PaymentOption::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cash',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $this->postJson('/api/cashier-shifts/open', [
            'opening_cash' => 100000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $createBill = $this->postJson('/api/open-bills', [
            'table_id' => $table->id,
            'customer_name' => 'Cash Guard',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $openBillId = $createBill->json('data.id');

        $this->postJson("/api/open-bills/{$openBillId}/add-item", [
            'fulfillment_type' => 'dine-in',
            'menu_item_id' => $menuItem->id,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $this->postJson("/api/open-bills/{$openBillId}/checkout", [
            'payment_method_id' => $payment->id,
            'payment_method_name' => 'Cash',
            'cash_received' => 10000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Nominal pembayaran kurang dari total.');
    }

    public function test_open_bill_non_cash_checkout_keeps_cash_fields_null_and_orders_index_exposes_them(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant QRIS', 'qris@example.com', 'password123', '7890');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '7890');

        $table = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Meja QRIS',
            'type' => 'standard',
            'hourly_rate' => 20000,
        ]);

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Drink',
            'emoji' => '🥤',
        ]);

        $menuItem = MenuItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Es Jeruk',
            'category_id' => $category->id,
            'legacy_category' => 'drink',
            'price' => 18000,
            'cost' => 5000,
            'is_available' => true,
        ]);

        $payment = PaymentOption::create([
            'tenant_id' => $tenant->id,
            'name' => 'QRIS',
            'type' => 'qris',
            'is_active' => true,
            'requires_reference' => true,
        ]);

        $this->postJson('/api/cashier-shifts/open', [
            'opening_cash' => 100000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $createBill = $this->postJson('/api/open-bills', [
            'table_id' => $table->id,
            'customer_name' => 'QRIS Guest',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $openBillId = $createBill->json('data.id');

        $this->postJson("/api/open-bills/{$openBillId}/add-item", [
            'fulfillment_type' => 'dine-in',
            'menu_item_id' => $menuItem->id,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $checkout = $this->postJson("/api/open-bills/{$openBillId}/checkout", [
            'payment_method_id' => $payment->id,
            'payment_method_name' => 'QRIS',
            'payment_reference' => 'REF-QRIS-001',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $checkout
            ->assertJsonPath('data.payment_method_name', 'QRIS')
            ->assertJsonPath('data.cash_received', null)
            ->assertJsonPath('data.change_amount', null);

        $this->getJson('/api/orders?search=REF-QRIS-001', [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.cash_received', null)
            ->assertJsonPath('data.0.change_amount', null);
    }

    public function test_open_bill_receipt_returns_database_backed_draft_snapshot(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Draft', 'draft@example.com', 'password123', '9876');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '9876');

        $table = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Meja Draft',
            'type' => 'standard',
            'hourly_rate' => 20000,
        ]);

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Drink',
            'emoji' => '☕',
        ]);

        $menuItem = MenuItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Es Kopi',
            'category_id' => $category->id,
            'legacy_category' => 'drink',
            'price' => 18000,
            'cost' => 6000,
            'emoji' => '☕',
            'is_available' => true,
        ]);

        $this->postJson('/api/cashier-shifts/open', [
            'opening_cash' => 100000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $createBill = $this->postJson('/api/open-bills', [
            'table_id' => $table->id,
            'customer_name' => 'Draft Customer',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $openBillId = $createBill->json('data.id');

        $this->postJson("/api/open-bills/{$openBillId}/add-item", [
            'fulfillment_type' => 'dine-in',
            'menu_item_id' => $menuItem->id,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $receipt = $this->getJson("/api/open-bills/{$openBillId}/receipt", [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $receipt
            ->assertJsonPath('data.kind', 'draft-open-bill')
            ->assertJsonPath('data.code', $createBill->json('data.code'))
            ->assertJsonPath('data.customer_name', 'Draft Customer')
            ->assertJsonPath('data.bill_type', 'dine-in')
            ->assertJsonPath('data.groups.0.items.0.menu_item_name', 'Es Kopi')
            ->assertJsonPath('data.totals.order_total', 18000)
            ->assertJsonPath('data.totals.final_total', 18000);
    }

    public function test_refund_reason_is_persisted_and_report_endpoints_include_refund_metrics(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Refund', 'refund@example.com', 'password123', '5555');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '5555');

        $billiardTable = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Table Refund',
            'type' => 'standard',
            'hourly_rate' => 25000,
        ]);

        $cafeTable = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Meja Refund',
            'type' => 'standard',
            'hourly_rate' => 20000,
        ]);

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Drink',
            'emoji' => '🥤',
        ]);

        $menuItem = MenuItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Lemon Tea',
            'category_id' => $category->id,
            'legacy_category' => 'drink',
            'price' => 12000,
            'cost' => 3000,
            'emoji' => '🥤',
            'is_available' => true,
        ]);

        $payment = PaymentOption::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cash',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $this->postJson('/api/cashier-shifts/open', [
            'opening_cash' => 100000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $this->postJson("/api/tables/{$billiardTable->id}/start-session", [
            'session_type' => 'billiard',
            'billing_mode' => 'open-bill',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $this->postJson("/api/tables/{$billiardTable->id}/add-order", [
            'menu_item_id' => $menuItem->id,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $billiardCheckout = $this->postJson("/api/tables/{$billiardTable->id}/checkout", [
            'payment_method_id' => $payment->id,
            'payment_method_name' => 'Cash',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $billiardOrderId = $billiardCheckout->json('data.id');
        $billiardTotal = (int) $billiardCheckout->json('data.grand_total');
        $billiardReason = 'Customer batal main setelah pembayaran.';

        $this->postJson("/api/orders/{$billiardOrderId}/refund", [
            'reason' => $billiardReason,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.refund_reason', $billiardReason)
            ->assertJsonPath('data.status', 'refunded');

        $createBill = $this->postJson('/api/open-bills', [
            'table_id' => $cafeTable->id,
            'customer_name' => 'Refund Cafe',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $openBillId = $createBill->json('data.id');

        $this->postJson("/api/open-bills/{$openBillId}/add-item", [
            'fulfillment_type' => 'dine-in',
            'menu_item_id' => $menuItem->id,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $fnbCheckout = $this->postJson("/api/open-bills/{$openBillId}/checkout", [
            'payment_method_id' => $payment->id,
            'payment_method_name' => 'Cash',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $fnbOrderId = $fnbCheckout->json('data.id');
        $fnbTotal = (int) $fnbCheckout->json('data.grand_total');
        $fnbReason = 'Minuman salah racik.';

        $this->postJson("/api/orders/{$fnbOrderId}/refund", [
            'reason' => $fnbReason,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.refund_reason', $fnbReason)
            ->assertJsonPath('data.status', 'refunded');

        $orders = $this->getJson('/api/orders', [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $this->assertContains($billiardReason, collect($orders->json('data'))->pluck('refund_reason')->all());
        $this->assertContains($fnbReason, collect($orders->json('data'))->pluck('refund_reason')->all());

        $this->getJson('/api/reports/billiard', [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.gross_revenue', $billiardTotal)
            ->assertJsonPath('data.refund_total', $billiardTotal)
            ->assertJsonPath('data.refund_count', 1)
            ->assertJsonPath('data.net_revenue', 0)
            ->assertJsonPath('data.recent_refunds.0.refund_reason', $billiardReason);

        $this->getJson('/api/reports/fnb', [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.gross_revenue', $fnbTotal)
            ->assertJsonPath('data.refund_total', $fnbTotal)
            ->assertJsonPath('data.refund_count', 1)
            ->assertJsonPath('data.net_revenue', 0)
            ->assertJsonPath('data.recent_refunds.0.refund_reason', $fnbReason);
    }

    public function test_dashboard_report_returns_mobile_parity_metrics(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Dashboard', 'dashboard@example.com', 'password123', '1111');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '1111');

        Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Table Dash',
            'type' => 'standard',
            'hourly_rate' => 25000,
            'status' => 'occupied',
        ]);

        Order::create([
            'tenant_id' => $tenant->id,
            'table_id' => null,
            'table_name' => 'Walk In',
            'table_type' => 'standard',
            'session_type' => 'cafe',
            'bill_type' => 'open-bill',
            'start_time' => now()->subMinutes(20),
            'end_time' => now(),
            'duration_minutes' => 20,
            'session_duration_hours' => 0,
            'rental_cost' => 0,
            'order_total' => 42000,
            'grand_total' => 42000,
            'order_cost' => 16000,
            'served_by' => $admin->name,
            'status' => 'completed',
            'created_at' => now()->subMinutes(10),
        ]);

        $response = $this->getJson('/api/reports/dashboard', [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $response
            ->assertJsonPath('data.today_revenue', 42000)
            ->assertJsonPath('data.today_orders', 1)
            ->assertJsonPath('data.avg_transaction', 42000)
            ->assertJsonPath('data.occupied_tables', 1);

        $this->assertNotEmpty($response->json('data.hourly_revenue'));
        $this->assertNotEmpty($response->json('data.recent_transactions'));
    }

    public function test_orders_endpoint_supports_filter_and_per_page_for_mobile_history(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Orders', 'orders@example.com', 'password123', '2222');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '2222');

        Order::create([
            'tenant_id' => $tenant->id,
            'table_id' => null,
            'table_name' => 'Bill A',
            'table_type' => 'standard',
            'session_type' => 'billiard',
            'bill_type' => 'open-bill',
            'start_time' => now()->subHour(),
            'end_time' => now(),
            'duration_minutes' => 60,
            'session_duration_hours' => 1,
            'rental_cost' => 30000,
            'order_total' => 20000,
            'grand_total' => 50000,
            'order_cost' => 15000,
            'served_by' => $admin->name,
            'status' => 'completed',
            'created_at' => now()->subMinutes(30),
        ]);

        Order::create([
            'tenant_id' => $tenant->id,
            'table_id' => null,
            'table_name' => 'Bill B',
            'table_type' => 'standard',
            'session_type' => 'cafe',
            'bill_type' => 'open-bill',
            'start_time' => now()->subHours(2),
            'end_time' => now()->subHour(),
            'duration_minutes' => 30,
            'session_duration_hours' => 0,
            'rental_cost' => 0,
            'order_total' => 18000,
            'grand_total' => 18000,
            'order_cost' => 6000,
            'served_by' => $admin->name,
            'status' => 'refunded',
            'refund_reason' => 'Double charge',
            'refunded_by' => $admin->name,
            'refunded_at' => now()->subMinutes(20),
            'created_at' => now()->subMinutes(40),
        ]);

        $response = $this->getJson('/api/orders?status=completed&session_type=billiard&per_page=1', [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $response
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.session_type', 'billiard')
            ->assertJsonPath('data.0.status', 'completed');
    }

    public function test_non_admin_refund_uses_staff_session_audit_without_extra_authorization(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Approval', 'approval@example.com', 'password123', '9999');
        $cashier = $this->createCashier($tenant, 'Cashier Approval', '123456');
        $staffToken = $this->loginAsStaff($tenant, $cashier, 'password123', '123456');

        $payment = PaymentOption::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cash',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $this->postJson('/api/cashier-shifts/open', [
            'opening_cash' => 50000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'table_name' => 'Meja 10',
            'table_type' => 'standard',
            'session_type' => 'cafe',
            'bill_type' => 'dine-in',
            'start_time' => now()->subMinutes(15),
            'end_time' => now(),
            'served_by' => $cashier->name,
            'status' => 'completed',
            'order_total' => 45000,
            'grand_total' => 45000,
            'payment_method_id' => $payment->id,
            'payment_method_name' => 'Cash',
            'payment_method_type' => 'cash',
        ]);

        $this->postJson("/api/orders/{$order->id}/refund", [
            'reason' => 'Kasir coba refund langsung',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.refund_authorization_method', 'staff-session')
            ->assertJsonPath('data.refund_authorized_by', $cashier->name)
            ->assertJsonPath('data.refund_authorized_role', 'kasir');
    }

    public function test_open_bill_store_accepts_initial_groups_items_and_member_meta(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Snapshot Draft', 'owner-refund@example.com', 'password123', '2222');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '2222');

        $table = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Table Snapshot',
            'type' => 'standard',
            'hourly_rate' => 20000,
        ]);

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Food',
            'emoji' => '🍔',
        ]);

        $menuItem = MenuItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Burger',
            'category_id' => $category->id,
            'legacy_category' => 'food',
            'price' => 22000,
            'cost' => 8000,
            'emoji' => '🍔',
            'is_available' => true,
        ]);

        $member = Member::create([
            'tenant_id' => $tenant->id,
            'code' => 'MEM-0001',
            'name' => 'Snapshot Member',
            'phone' => '08123456789',
            'email' => 'snapshot-member@example.com',
            'points_balance' => 120,
        ]);

        $this->postJson('/api/cashier-shifts/open', [
            'opening_cash' => 50000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $response = $this->postJson('/api/open-bills', [
            'customer_name' => 'Snapshot Guest',
            'member_id' => $member->id,
            'points_to_redeem' => 25,
            'groups' => [
                [
                    'fulfillment_type' => 'dine-in',
                    'table_id' => $table->id,
                    'items' => [
                        [
                            'menu_item_id' => $menuItem->id,
                            'quantity' => 2,
                            'note' => 'No onions',
                        ],
                    ],
                ],
            ],
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertCreated()
            ->assertJsonPath('data.customer_name', 'Snapshot Guest')
            ->assertJsonPath('data.member_id', $member->id)
            ->assertJsonPath('data.points_to_redeem', 25)
            ->assertJsonPath('data.groups.0.fulfillment_type', 'dine-in')
            ->assertJsonPath('data.groups.0.table_id', $table->id)
            ->assertJsonPath('data.groups.0.items.0.menu_item_id', $menuItem->id)
            ->assertJsonPath('data.groups.0.items.0.quantity', 2)
            ->assertJsonPath('data.groups.0.items.0.note', 'No onions');

        $table->refresh();
        $this->assertNotNull($table->active_open_bill_id);
        $this->assertSame(2, OpenBill::findOrFail($response->json('data.id'))->groups()->first()->items()->sum('quantity'));
    }

    public function test_table_append_draft_orders_creates_linked_open_bill_for_active_table(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Append Draft', 'append-draft@example.com', 'password123', '2255');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '2255');

        $table = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Meja 9',
            'type' => 'standard',
            'hourly_rate' => 20000,
            'status' => 'occupied',
            'start_time' => now()->subMinutes(10),
            'session_type' => 'billiard',
            'billing_mode' => 'open-bill',
        ]);

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Coffee',
            'emoji' => '☕',
        ]);

        $menuItem = MenuItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Americano',
            'category_id' => $category->id,
            'legacy_category' => 'drink',
            'price' => 18000,
            'cost' => 6000,
            'emoji' => '☕',
            'is_available' => true,
        ]);

        $this->postJson('/api/cashier-shifts/open', [
            'opening_cash' => 100000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $response = $this->postJson("/api/tables/{$table->id}/draft-orders", [
            'customer_name' => 'Walk-in',
            'groups' => [
                [
                    'fulfillment_type' => 'dine-in',
                    'items' => [
                        [
                            'menu_item_id' => $menuItem->id,
                            'quantity' => 2,
                            'note' => 'Less ice',
                        ],
                    ],
                ],
            ],
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.customer_name', 'Walk-in')
            ->assertJsonPath('data.groups.0.fulfillment_type', 'dine-in')
            ->assertJsonPath('data.groups.0.table_id', $table->id)
            ->assertJsonPath('data.groups.0.items.0.menu_item_id', $menuItem->id)
            ->assertJsonPath('data.groups.0.items.0.quantity', 2)
            ->assertJsonPath('data.groups.0.items.0.note', 'Less ice')
            ->assertJsonPath('table_bill.items.0.menu_item_id', $menuItem->id);

        $this->assertNotEmpty($response->json('active_open_bill_id'));

        $table->refresh();
        $this->assertNotNull($table->active_open_bill_id);
        $bill = OpenBill::findOrFail($table->active_open_bill_id);
        $this->assertSame('Walk-in', $bill->customer_name);
        $this->assertSame(2, $bill->groups()->first()->items()->sum('quantity'));
    }

    public function test_table_append_draft_orders_appends_to_existing_linked_open_bill(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Append Linked', 'append-linked@example.com', 'password123', '2266');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '2266');

        $table = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Meja 10',
            'type' => 'standard',
            'hourly_rate' => 20000,
            'status' => 'occupied',
            'start_time' => now()->subMinutes(15),
            'session_type' => 'billiard',
            'billing_mode' => 'open-bill',
        ]);

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Coffee',
            'emoji' => '☕',
        ]);

        $menuItem = MenuItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Americano',
            'category_id' => $category->id,
            'legacy_category' => 'drink',
            'price' => 18000,
            'cost' => 6000,
            'emoji' => '☕',
            'is_available' => true,
        ]);

        $this->postJson('/api/cashier-shifts/open', [
            'opening_cash' => 100000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $linkedBillResponse = $this->postJson('/api/open-bills', [
            'customer_name' => 'Initial Guest',
            'groups' => [
                [
                    'fulfillment_type' => 'dine-in',
                    'table_id' => $table->id,
                    'items' => [
                        [
                            'menu_item_id' => $menuItem->id,
                            'quantity' => 1,
                        ],
                    ],
                ],
            ],
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertCreated();

        $linkedBillId = $linkedBillResponse->json('data.id');
        $table->refresh();
        $this->assertSame($linkedBillId, $table->active_open_bill_id);

        $this->postJson("/api/tables/{$table->id}/draft-orders", [
            'customer_name' => 'Merged Guest',
            'groups' => [
                [
                    'fulfillment_type' => 'dine-in',
                    'items' => [
                        [
                            'menu_item_id' => $menuItem->id,
                            'quantity' => 2,
                            'note' => 'No sugar',
                        ],
                    ],
                ],
            ],
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('active_open_bill_id', $linkedBillId)
            ->assertJsonPath('data.customer_name', 'Merged Guest')
            ->assertJsonPath('data.groups.0.items.0.quantity', 3)
            ->assertJsonPath('data.groups.0.items.0.note', 'No sugar');

        $table->refresh();
        $this->assertSame($linkedBillId, $table->active_open_bill_id);

        $bill = OpenBill::findOrFail($linkedBillId)->load('groups.items');
        $this->assertSame(3, $bill->groups->first()->items->first()->quantity);
        $this->assertSame('No sugar', $bill->groups->first()->items->first()->note);
    }

    public function test_table_close_to_draft_creates_frozen_standalone_draft_and_frees_table(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Close Draft', 'close-draft@example.com', 'password123', '2277');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '2277');

        $table = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Meja 12',
            'type' => 'standard',
            'hourly_rate' => 30000,
            'status' => 'occupied',
            'start_time' => now()->subMinutes(70),
            'session_type' => 'billiard',
            'billing_mode' => 'package',
            'selected_package_name' => 'Paket 2 Jam',
            'selected_package_hours' => 2,
            'selected_package_price' => 60000,
            'package_minutes_total' => 120,
            'package_total_price' => 60000,
            'origin_staff_id' => $admin->id,
            'origin_staff_name' => $admin->name,
        ]);

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Coffee',
            'emoji' => '☕',
        ]);
        $menuItem = MenuItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Latte',
            'category_id' => $category->id,
            'legacy_category' => 'drink',
            'price' => 20000,
            'cost' => 6000,
            'emoji' => '☕',
            'is_available' => true,
        ]);
        TableOrderItem::create([
            'tenant_id' => $tenant->id,
            'table_id' => $table->id,
            'menu_item_id' => $menuItem->id,
            'quantity' => 2,
            'unit_price' => 20000,
            'added_at' => now()->subMinutes(30),
        ]);

        $this->openShift($staffToken);

        $response = $this->postJson("/api/tables/{$table->id}/close-to-draft", [], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.locked_final', true)
            ->assertJsonPath('data.source_table_id', $table->id)
            ->assertJsonPath('data.source_table_name', 'Meja 12')
            ->assertJsonPath('data.session_charge_name', 'Paket 2 Jam')
            ->assertJsonPath('data.groups.0.items.0.menu_item_id', $menuItem->id)
            ->assertJsonPath('data.groups.0.items.0.quantity', 2);

        $draftId = $response->json('data.id');
        $draft = OpenBill::findOrFail($draftId);
        $this->assertTrue($draft->locked_final);
        $this->assertSame('draft', $draft->status->value);

        $table->refresh();
        $this->assertSame('available', $table->status->value);
        $this->assertNull($table->active_open_bill_id);
        $this->assertSame(0, $table->orderItems()->count());
    }

    public function test_table_close_to_draft_moves_only_linked_table_group_and_keeps_unrelated_open_bill_groups(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Split Draft', 'split-draft@example.com', 'password123', '2288');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '2288');

        $table = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Meja 13',
            'type' => 'standard',
            'hourly_rate' => 25000,
            'status' => 'occupied',
            'start_time' => now()->subMinutes(45),
            'session_type' => 'billiard',
            'billing_mode' => 'open-bill',
        ]);

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Beverage',
            'emoji' => '🥤',
        ]);
        $menuItem = MenuItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Tea',
            'category_id' => $category->id,
            'legacy_category' => 'drink',
            'price' => 15000,
            'cost' => 4000,
            'emoji' => '🥤',
            'is_available' => true,
        ]);

        $this->openShift($staffToken);

        $createBill = $this->postJson('/api/open-bills', [
            'customer_name' => 'Walk-in Split',
            'groups' => [
                [
                    'fulfillment_type' => 'dine-in',
                    'table_id' => $table->id,
                    'items' => [
                        [
                            'menu_item_id' => $menuItem->id,
                            'quantity' => 1,
                        ],
                    ],
                ],
                [
                    'fulfillment_type' => 'takeaway',
                    'items' => [
                        [
                            'menu_item_id' => $menuItem->id,
                            'quantity' => 2,
                        ],
                    ],
                ],
            ],
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertCreated();

        $linkedBillId = $createBill->json('data.id');

        $response = $this->postJson("/api/tables/{$table->id}/close-to-draft", [], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.groups.0.items.0.quantity', 1);

        $draft = OpenBill::findOrFail($response->json('data.id'))->load('groups.items');
        $this->assertSame(1, $draft->groups->first()->items->sum('quantity'));

        $remainingOpenBill = OpenBill::findOrFail($linkedBillId)->load('groups.items');
        $this->assertCount(1, $remainingOpenBill->groups);
        $this->assertSame('takeaway', $remainingOpenBill->groups->first()->fulfillment_type->value);
        $this->assertSame(2, $remainingOpenBill->groups->first()->items->sum('quantity'));
    }

    public function test_frozen_table_draft_is_returned_by_status_filter_rejects_mutations_and_can_checkout(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Frozen Draft', 'frozen-draft@example.com', 'password123', '2299');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '2299');

        $table = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Meja 14',
            'type' => 'vip',
            'hourly_rate' => 40000,
            'status' => 'occupied',
            'start_time' => now()->subMinutes(80),
            'session_type' => 'billiard',
            'billing_mode' => 'open-bill',
        ]);

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Snack',
            'emoji' => '🍟',
        ]);
        $menuItem = MenuItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Fries',
            'category_id' => $category->id,
            'legacy_category' => 'food',
            'price' => 25000,
            'cost' => 7000,
            'emoji' => '🍟',
            'is_available' => true,
        ]);
        TableOrderItem::create([
            'tenant_id' => $tenant->id,
            'table_id' => $table->id,
            'menu_item_id' => $menuItem->id,
            'quantity' => 1,
            'unit_price' => 25000,
            'added_at' => now()->subMinutes(20),
        ]);

        $payment = PaymentOption::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cash',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $this->openShift($staffToken);

        $closeResponse = $this->postJson("/api/tables/{$table->id}/close-to-draft", [], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $draftId = $closeResponse->json('data.id');

        $this->getJson('/api/open-bills', [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonMissingPath('data.0.id');

        $this->getJson('/api/open-bills?status=open,draft', [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonFragment([
                'id' => $draftId,
                'status' => 'draft',
            ]);

        $this->putJson("/api/open-bills/{$draftId}", [
            'customer_name' => 'Changed',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(422);

        $this->deleteJson("/api/open-bills/{$draftId}", [], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(422);

        $this->postJson("/api/open-bills/{$draftId}/add-item", [
            'fulfillment_type' => 'dine-in',
            'menu_item_id' => $menuItem->id,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(422);

        $this->postJson("/api/open-bills/{$draftId}/assign-table", [
            'table_id' => $table->id,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(422);

        $this->postJson("/api/open-bills/{$draftId}/attach-member", [
            'member_id' => null,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(422);

        $receipt = $this->getJson("/api/open-bills/{$draftId}/receipt", [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.locked_final', true)
            ->assertJsonPath('data.session_charge_total', 54000)
            ->assertJsonPath('data.totals.final_total', 79000);

        $checkout = $this->postJson("/api/open-bills/{$draftId}/checkout", [
            'payment_method_id' => $payment->id,
            'payment_method_name' => 'Cash',
            'cash_received' => 150000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.table_id', $table->id)
            ->assertJsonPath('data.table_type', 'vip')
            ->assertJsonPath('data.bill_type', 'billiard')
            ->assertJsonPath('data.rental_cost', 54000)
            ->assertJsonPath('data.duration_minutes', 80)
            ->assertJsonPath('data.grand_total', 79000);

        $this->assertNull(OpenBill::find($draftId));
    }

    public function test_stock_is_restored_when_table_and_open_bill_items_are_reduced_removed_or_deleted(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Stock Guard', 'stock@example.com', 'password123', '4444');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '4444');

        $table = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Meja Stok',
            'type' => 'standard',
            'hourly_rate' => 20000,
        ]);

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Snack',
            'emoji' => '🍟',
        ]);

        $ingredient = Ingredient::create([
            'tenant_id' => $tenant->id,
            'name' => 'Kentang',
            'unit' => 'gram',
            'stock' => 10,
            'min_stock' => 1,
            'unit_cost' => 1000,
        ]);

        $menuItem = MenuItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'French Fries',
            'category_id' => $category->id,
            'legacy_category' => 'food',
            'price' => 15000,
            'cost' => 5000,
            'is_available' => true,
        ]);

        MenuItemRecipe::create([
            'tenant_id' => $tenant->id,
            'menu_item_id' => $menuItem->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => 2,
        ]);

        $this->postJson('/api/cashier-shifts/open', [
            'opening_cash' => 100000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $this->postJson("/api/tables/{$table->id}/start-session", [
            'session_type' => 'billiard',
            'billing_mode' => 'open-bill',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $this->postJson("/api/tables/{$table->id}/add-order", [
            'menu_item_id' => $menuItem->id,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();
        $this->assertSame(8.0, $ingredient->fresh()->stock);

        $this->putJson("/api/tables/{$table->id}/update-order/{$menuItem->id}", [
            'quantity' => 3,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();
        $this->assertSame(4.0, $ingredient->fresh()->stock);

        $this->putJson("/api/tables/{$table->id}/update-order/{$menuItem->id}", [
            'quantity' => 1,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();
        $this->assertSame(8.0, $ingredient->fresh()->stock);

        $this->deleteJson("/api/tables/{$table->id}/remove-order/{$menuItem->id}", [], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();
        $this->assertSame(10.0, $ingredient->fresh()->stock);

        $createBill = $this->postJson('/api/open-bills', [
            'table_id' => $table->id,
            'customer_name' => 'Stok Bill',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $openBillId = $createBill->json('data.id');

        $this->postJson("/api/open-bills/{$openBillId}/add-item", [
            'fulfillment_type' => 'dine-in',
            'menu_item_id' => $menuItem->id,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();
        $this->assertSame(8.0, $ingredient->fresh()->stock);

        $this->putJson("/api/open-bills/{$openBillId}", [
            'points_to_redeem' => 0,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $this->putJson("/api/open-bills/{$openBillId}/update-item", [
            'fulfillment_type' => 'dine-in',
            'menu_item_id' => $menuItem->id,
            'quantity' => 3,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();
        $this->assertSame(4.0, $ingredient->fresh()->stock);

        $this->putJson("/api/open-bills/{$openBillId}/update-item", [
            'fulfillment_type' => 'dine-in',
            'menu_item_id' => $menuItem->id,
            'quantity' => 1,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();
        $this->assertSame(8.0, $ingredient->fresh()->stock);

        $this->deleteJson("/api/open-bills/{$openBillId}/remove-item", [
            'fulfillment_type' => 'dine-in',
            'menu_item_id' => $menuItem->id,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();
        $this->assertSame(10.0, $ingredient->fresh()->stock);

        $this->postJson("/api/open-bills/{$openBillId}/add-item", [
            'fulfillment_type' => 'dine-in',
            'menu_item_id' => $menuItem->id,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();
        $this->assertSame(8.0, $ingredient->fresh()->stock);

        $this->deleteJson("/api/open-bills/{$openBillId}", [], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();
        $this->assertSame(10.0, $ingredient->fresh()->stock);
    }

    public function test_reports_use_refunded_at_and_orders_index_uses_end_of_day_searchable_filters(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Filter Guard', 'filter@example.com', 'password123', '4545');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '4545');

        $payment = PaymentOption::create([
            'tenant_id' => $tenant->id,
            'name' => 'QRIS',
            'type' => 'qris',
            'is_active' => true,
            'requires_reference' => true,
        ]);

        $this->postJson('/api/cashier-shifts/open', [
            'opening_cash' => 100000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'table_name' => 'Meja Filter',
            'table_type' => 'standard',
            'session_type' => 'cafe',
            'bill_type' => 'dine-in',
            'start_time' => now()->subDay()->subMinutes(15),
            'end_time' => now()->subDay(),
            'served_by' => $admin->name,
            'status' => 'completed',
            'order_total' => 45000,
            'grand_total' => 45000,
            'payment_method_id' => $payment->id,
            'payment_method_name' => 'QRIS',
            'payment_method_type' => 'non-cash',
            'payment_reference' => 'REF-END-OF-DAY',
            'created_at' => now()->subDay()->setTime(23, 30),
            'updated_at' => now()->subDay()->setTime(23, 30),
        ]);

        $this->postJson("/api/orders/{$order->id}/refund", [
            'reason' => 'Refund hari ini',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $today = now()->toDateString();

        $this->getJson("/api/reports/fnb?from={$today}&to={$today}", [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.refund_count', 1)
            ->assertJsonPath('data.recent_refunds.0.id', $order->id);

        $lateOrder = Order::create([
            'tenant_id' => $tenant->id,
            'table_name' => 'Meja Malam',
            'table_type' => 'standard',
            'session_type' => 'cafe',
            'bill_type' => 'takeaway',
            'start_time' => now()->setTime(21, 0),
            'end_time' => now()->setTime(21, 30),
            'served_by' => $admin->name,
            'status' => 'completed',
            'order_total' => 55000,
            'grand_total' => 55000,
            'payment_method_id' => $payment->id,
            'payment_method_name' => 'QRIS',
            'payment_method_type' => 'non-cash',
            'payment_reference' => 'REF-LATE-ORDER',
            'created_at' => now()->setTime(21, 15),
            'updated_at' => now()->setTime(21, 15),
        ]);

        $this->getJson("/api/orders?to={$today}&search=REF-LATE-ORDER", [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lateOrder->id);
    }

    public function test_open_bill_creation_blocks_table_conflicts_and_generates_non_reused_codes(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Bill Code', 'billcode@example.com', 'password123', '5656');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '5656');

        $table = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Meja Konflik',
            'type' => 'standard',
            'hourly_rate' => 20000,
        ]);

        $this->postJson('/api/cashier-shifts/open', [
            'opening_cash' => 100000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $first = $this->postJson('/api/open-bills', [
            'table_id' => $table->id,
            'customer_name' => 'Pertama',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $this->postJson('/api/open-bills', [
            'table_id' => $table->id,
            'customer_name' => 'Kedua',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(422);

        $this->assertSame(1, OpenBill::count());

        $firstId = $first->json('data.id');
        $firstCode = $first->json('data.code');

        $this->deleteJson("/api/open-bills/{$firstId}", [], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $second = $this->postJson('/api/open-bills', [
            'customer_name' => 'Ketiga',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $this->assertNotSame($firstCode, $second->json('data.code'));
    }

    public function test_member_code_generation_and_validation_related_endpoints_are_guarded(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Guard More', 'guardmore@example.com', 'password123', '7878');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '7878');

        $this->postJson('/api/cashier-shifts/open', [
            'opening_cash' => 100000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $memberA = $this->postJson('/api/members', [
            'name' => 'Member A',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $codeA = $memberA->json('data.code');
        $this->assertNotEmpty($codeA);

        $this->deleteJson('/api/members/' . $memberA->json('data.id'), [], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $memberB = $this->postJson('/api/members', [
            'name' => 'Member B',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $this->assertNotSame($codeA, $memberB->json('data.code'));

        $ingredient = $this->postJson('/api/ingredients', [
            'name' => 'Teh Bubuk',
            'unit' => 'invalid-unit',
            'stock' => 10,
            'min_stock' => 1,
            'unit_cost' => 1000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ]);
        $ingredient->assertStatus(422);

        $table = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Meja WL',
            'type' => 'standard',
            'hourly_rate' => 10000,
        ]);

        $entry = $this->postJson('/api/waiting-list', [
            'customer_name' => 'Budi',
            'party_size' => 2,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $entryId = $entry->json('data.id');

        $this->postJson("/api/waiting-list/{$entryId}/seat", [
            'table_id' => $table->id,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $this->postJson("/api/waiting-list/{$entryId}/seat", [
            'table_id' => $table->id,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(422);

        $this->putJson('/api/table-layout/999999', [
            'x_percent' => 150,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(422);
    }

    public function test_payment_option_crud_enforces_system_default_parents_and_child_only_management(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Payment Guard', 'payguard@example.com', 'password123', '8989');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '8989');

        $list = $this->getJson('/api/payment-options', [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', 'Cash')
            ->assertJsonPath('data.0.is_system_default', true)
            ->assertJsonPath('data.1.name', 'QRIS')
            ->assertJsonPath('data.2.name', 'Transfer');

        $qrisParentId = collect($list->json('data'))
            ->firstWhere('type', 'qris')['id'];
        $transferParentId = collect($list->json('data'))
            ->firstWhere('type', 'transfer')['id'];
        $cashParentId = collect($list->json('data'))
            ->firstWhere('type', 'cash')['id'];

        $this->postJson('/api/payment-options', [
            'name' => 'Top Level Custom',
            'is_active' => true,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);

        $child = $this->postJson('/api/payment-options', [
            'name' => 'BNI',
            'is_active' => true,
            'requires_reference' => true,
            'reference_label' => 'RRN',
            'parent_id' => $qrisParentId,
            'sort_order' => 2,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201)
            ->assertJsonPath('data.type', 'qris')
            ->assertJsonPath('data.parent_id', $qrisParentId);

        $childId = $child->json('data.id');

        $this->getJson('/api/payment-options', [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.1.id', $qrisParentId)
            ->assertJsonPath('data.1.children.0.id', $childId)
            ->assertJsonPath('data.1.children.0.name', 'BNI')
            ->assertJsonPath('data.1.children.0.requires_reference', true);

        $this->putJson("/api/payment-options/{$childId}", [
            'name' => 'Mandiri',
            'parent_id' => $transferParentId,
            'reference_label' => 'Reference ID',
            'sort_order' => 5,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.name', 'Mandiri')
            ->assertJsonPath('data.type', 'transfer')
            ->assertJsonPath('data.parent_id', $transferParentId)
            ->assertJsonPath('data.reference_label', 'Reference ID')
            ->assertJsonPath('data.sort_order', 5);

        $this->putJson("/api/payment-options/{$childId}", [
            'requires_reference' => true,
            'reference_label' => '',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['reference_label']);

        $this->putJson("/api/payment-options/{$cashParentId}", [
            'name' => 'Cash Custom',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(422);

        $this->deleteJson("/api/payment-options/{$cashParentId}", [], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(422);

        $this->deleteJson("/api/payment-options/{$childId}", [], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();
    }

    public function test_payment_method_report_returns_parent_and_child_breakdown(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Payment Report', 'payment-report@example.com', 'password123', '4545');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '4545');

        $defaults = app(\App\Services\PaymentOptionService::class)
            ->ensureSystemDefaultsForTenant($tenant->id)
            ->keyBy(fn (PaymentOption $option) => $option->type->value);

        $cashParent = $defaults['cash'];
        $qrisParent = $defaults['qris'];

        $qrisChild = PaymentOption::create([
            'tenant_id' => $tenant->id,
            'name' => 'BNI',
            'type' => 'qris',
            'is_active' => true,
            'requires_reference' => true,
            'reference_label' => 'RRN',
            'parent_id' => $qrisParent->id,
            'is_group' => false,
            'is_system_default' => false,
        ]);

        Order::create([
            'tenant_id' => $tenant->id,
            'table_name' => 'Walk In',
            'table_type' => 'standard',
            'session_type' => 'cafe',
            'bill_type' => 'dine-in',
            'start_time' => now()->subHour(),
            'end_time' => now(),
            'duration_minutes' => 60,
            'order_total' => 50000,
            'grand_total' => 50000,
            'order_cost' => 20000,
            'served_by' => $admin->name,
            'status' => 'completed',
            'payment_method_id' => $cashParent->id,
            'payment_method_name' => 'Cash',
            'payment_method_type' => 'cash',
        ]);

        Order::create([
            'tenant_id' => $tenant->id,
            'table_name' => 'Walk In',
            'table_type' => 'standard',
            'session_type' => 'cafe',
            'bill_type' => 'dine-in',
            'start_time' => now()->subMinutes(90),
            'end_time' => now(),
            'duration_minutes' => 90,
            'order_total' => 75000,
            'grand_total' => 75000,
            'order_cost' => 25000,
            'served_by' => $admin->name,
            'status' => 'completed',
            'payment_method_id' => $qrisChild->id,
            'payment_method_name' => 'QRIS - BNI',
            'payment_method_type' => 'non-cash',
        ]);

        $report = $this->getJson('/api/reports/payment-methods', [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $parents = collect($report->json('data.parents'))->keyBy('parent_type');

        $this->assertSame(2, $report->json('data.total_transactions'));
        $this->assertSame(125000, $report->json('data.gross_revenue'));
        $this->assertSame(50000, $parents['cash']['gross_revenue']);
        $this->assertSame('Direct', $parents['cash']['children'][0]['child_name']);
        $this->assertSame(75000, $parents['qris']['gross_revenue']);
        $this->assertSame('BNI', $parents['qris']['children'][0]['child_name']);
    }

    public function test_package_bill_exposes_remaining_minutes_and_expiry_state(): void
    {
        $fixedNow = \Illuminate\Support\Carbon::parse('2026-04-23 12:00:00');
        \Illuminate\Support\Carbon::setTestNow($fixedNow);
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Package', 'package@example.com', 'password123', '1212');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '1212');

        $table = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Table Package',
            'type' => 'standard',
            'hourly_rate' => 20000,
        ]);

        $package = BilliardPackage::create([
            'tenant_id' => $tenant->id,
            'name' => 'Paket 2 Jam',
            'duration_hours' => 2,
            'price' => 50000,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->postJson('/api/cashier-shifts/open', [
            'opening_cash' => 100000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $this->postJson("/api/tables/{$table->id}/start-session", [
            'session_type' => 'billiard',
            'billing_mode' => 'package',
            'package_id' => $package->id,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $table->refresh();
        $table->forceFill([
            'start_time' => now()->subMinutes(30),
            'package_expired_at' => now()->addMinutes(90),
        ])->save();

        $this->getJson("/api/tables/{$table->id}", [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.package_included_minutes_total', 120)
            ->assertJsonPath('data.remaining_package_minutes', 90)
            ->assertJsonPath('data.is_package_expired', false)
            ->assertJsonPath('data.is_in_grace_period', false)
            ->assertJsonPath('data.package_total_price', 50000)
            ->assertJsonPath('data.package_expired_at', $fixedNow->copy()->addMinutes(90)->toJSON());

        $this->getJson("/api/tables/{$table->id}/bill", [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.package_included_minutes_total', 120)
            ->assertJsonPath('data.remaining_package_minutes', 90)
            ->assertJsonPath('data.rental_cost', 50000)
            ->assertJsonPath('data.is_package_expired', false);

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_extend_package_accumulates_minutes_and_price_and_resets_reminder(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Extend', 'extend@example.com', 'password123', '2323');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '2323');

        $table = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Table Extend',
            'type' => 'standard',
            'hourly_rate' => 20000,
        ]);

        $firstPackage = BilliardPackage::create([
            'tenant_id' => $tenant->id,
            'name' => 'Paket 1 Jam',
            'duration_hours' => 1,
            'price' => 30000,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $secondPackage = BilliardPackage::create([
            'tenant_id' => $tenant->id,
            'name' => 'Paket 2 Jam',
            'duration_hours' => 2,
            'price' => 45000,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $this->postJson('/api/cashier-shifts/open', [
            'opening_cash' => 100000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $this->postJson("/api/tables/{$table->id}/start-session", [
            'session_type' => 'billiard',
            'billing_mode' => 'package',
            'package_id' => $firstPackage->id,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $table->refresh();
        $table->update([
            'package_reminder_shown_at' => now(),
        ]);

        $this->postJson("/api/tables/{$table->id}/extend-package", [
            'package_id' => $secondPackage->id,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.selected_package.hours', 3)
            ->assertJsonPath('data.selected_package.price', 75000)
            ->assertJsonPath('data.package_included_minutes_total', 180)
            ->assertJsonPath('data.package_total_price', 75000)
            ->assertJsonPath('data.package_reminder_shown_at', null);
    }

    public function test_internal_scheduler_auto_closes_expired_package_to_draft_without_overrun(): void
    {
        $fixedNow = \Illuminate\Support\Carbon::parse('2026-04-23 12:00:00');
        \Illuminate\Support\Carbon::setTestNow($fixedNow);
        config(['internal_jobs.token' => 'secret-token']);

        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Scheduler', 'scheduler@example.com', 'password123', '3434');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '3434');

        $table = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Table Scheduler',
            'type' => 'standard',
            'hourly_rate' => 20000,
        ]);

        $package = BilliardPackage::create([
            'tenant_id' => $tenant->id,
            'name' => 'Paket 1 Jam',
            'duration_hours' => 1,
            'price' => 30000,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->postJson('/api/cashier-shifts/open', [
            'opening_cash' => 100000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $this->postJson("/api/tables/{$table->id}/start-session", [
            'session_type' => 'billiard',
            'billing_mode' => 'package',
            'package_id' => $package->id,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $table->refresh();
        $table->forceFill([
            'start_time' => now()->subMinutes(90),
            'package_expired_at' => now()->subMinutes(30),
        ])->save();

        $this->getJson('/internal/jobs/close-expired-package-sessions?token=secret-token')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('processed', 1)
            ->assertJsonPath('failed', 0);

        $table->refresh();
        $this->assertSame('available', $table->status->value);
        $this->assertNull($table->active_open_bill_id);

        $draft = OpenBill::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', OpenBillStatus::Draft)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($draft);
        $this->assertSame('package-expired-auto', $draft->close_reason?->value);
        $this->assertTrue($draft->locked_final);
        $this->assertSame(30000, $draft->session_charge_total);
        $this->assertSame(60, $draft->duration_minutes);
        $this->assertEquals($fixedNow->copy()->subMinutes(30)->toJSON(), $draft->session_ended_at?->toJSON());

        $this->getJson('/api/open-bills?status=open,draft', [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.0.close_reason', 'package-expired-auto')
            ->assertJsonPath('data.0.locked_final', true);

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_internal_scheduler_route_rejects_invalid_token_and_respects_active_lock(): void
    {
        config(['internal_jobs.token' => 'secret-token']);

        $this->getJson('/internal/jobs/close-expired-package-sessions?token=wrong-token')
            ->assertStatus(403);

        \Illuminate\Support\Facades\Cache::put(
            'internal-job:close-expired-package-sessions',
            now()->toISOString(),
            now()->addSeconds(55),
        );

        $this->getJson('/internal/jobs/close-expired-package-sessions?token=secret-token')
            ->assertOk()
            ->assertJsonPath('status', 'locked')
            ->assertJsonPath('processed', 0);

        \Illuminate\Support\Facades\Cache::forget('internal-job:close-expired-package-sessions');
    }

    public function test_tables_index_triggers_tenant_fallback_auto_close_when_scheduler_is_not_running(): void
    {
        $fixedNow = \Illuminate\Support\Carbon::parse('2026-04-25 12:00:00');
        \Illuminate\Support\Carbon::setTestNow($fixedNow);

        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Fallback', 'fallback@example.com', 'password123', '7878');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '7878');

        $table = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Table Fallback',
            'type' => 'standard',
            'hourly_rate' => 25000,
            'status' => 'occupied',
            'session_type' => 'billiard',
            'billing_mode' => 'package',
            'start_time' => $fixedNow->copy()->subMinutes(90),
            'selected_package_name' => 'Paket 1 Jam',
            'selected_package_hours' => 1,
            'selected_package_price' => 40000,
            'package_minutes_total' => 60,
            'package_total_price' => 40000,
            'package_expired_at' => $fixedNow->copy()->subMinutes(30),
            'origin_staff_id' => $admin->id,
            'origin_staff_name' => $admin->name,
        ]);

        $this->getJson('/api/tables', [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $table->refresh();
        $this->assertSame('available', $table->status->value);

        $draft = OpenBill::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('source_table_id', $table->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($draft);
        $this->assertSame('package-expired-auto', $draft->close_reason?->value);

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_read_endpoints_do_not_auto_close_or_convert_expired_package_sessions(): void
    {
        $fixedNow = \Illuminate\Support\Carbon::parse('2026-04-23 12:00:00');
        \Illuminate\Support\Carbon::setTestNow($fixedNow);
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Read Only Expiry', 'read-expiry@example.com', 'password123', '4545');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '4545');

        $table = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Table Read',
            'type' => 'standard',
            'hourly_rate' => 20000,
        ]);

        $firstPackage = BilliardPackage::create([
            'tenant_id' => $tenant->id,
            'name' => 'Paket 1 Jam',
            'duration_hours' => 1,
            'price' => 30000,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->postJson('/api/cashier-shifts/open', [
            'opening_cash' => 100000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $this->postJson("/api/tables/{$table->id}/start-session", [
            'session_type' => 'billiard',
            'billing_mode' => 'package',
            'package_id' => $firstPackage->id,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $table->refresh();
        $table->forceFill([
            'start_time' => now()->subMinutes(90),
            'package_expired_at' => now()->subMinutes(30),
        ])->save();

        $this->getJson("/api/tables/{$table->id}", [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.billing_mode', 'package')
            ->assertJsonPath('data.is_package_expired', true)
            ->assertJsonPath('data.is_in_grace_period', false)
            ->assertJsonPath('data.is_auto_converted_to_open_bill', false)
            ->assertJsonPath('data.next_package_reminder_due_at', null);

        $this->getJson("/api/tables/{$table->id}/bill", [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.rental_cost', 30000)
            ->assertJsonPath('data.duration_minutes', 60)
            ->assertJsonPath('data.remaining_package_minutes', 0)
            ->assertJsonPath('data.overrun_package_minutes', 0)
            ->assertJsonPath('data.active_overrun_minutes', 0)
            ->assertJsonPath('data.is_auto_converted_to_open_bill', false);

        $table->refresh();
        $this->assertSame('occupied', $table->status->value);
        $this->assertSame('package', $table->billing_mode?->value);

        $this->postJson("/api/tables/{$table->id}/extend-package", [
            'package_id' => $firstPackage->id,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Paket sudah habis. Mulai sesi baru dari meja yang sudah tersedia kembali.');

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_menu_item_store_rejects_duplicate_recipe_ingredients(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Menu Guard', 'menu-guard@example.com', 'password123', '5656');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '5656');

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Food',
            'emoji' => '🍽️',
        ]);

        $ingredient = Ingredient::create([
            'tenant_id' => $tenant->id,
            'name' => 'Nasi',
            'unit' => 'porsi',
            'stock' => 10,
            'min_stock' => 1,
            'unit_cost' => 5000,
        ]);

        $this->postJson('/api/menu-items', [
            'name' => 'Nasi Goreng',
            'category_id' => $category->id,
            'price' => 18000,
            'cost' => 7000,
            'is_available' => true,
            'recipe' => [
                [
                    'ingredient_id' => $ingredient->id,
                    'quantity' => 1,
                ],
                [
                    'ingredient_id' => $ingredient->id,
                    'quantity' => 0.5,
                ],
            ],
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(422)
            ->assertJsonValidationErrors([
                'recipe.0.ingredient_id',
                'recipe.1.ingredient_id',
            ]);

        $this->assertDatabaseMissing('menu_items', [
            'tenant_id' => $tenant->id,
            'name' => 'Nasi Goreng',
        ]);
    }

    public function test_menu_item_store_derives_cost_from_recipe_without_manual_cost(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Menu Cost', 'menu-cost@example.com', 'password123', '1212');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '1212');

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Food',
            'emoji' => '🍽️',
        ]);

        $ingredient = Ingredient::create([
            'tenant_id' => $tenant->id,
            'name' => 'Ayam',
            'unit' => 'porsi',
            'stock' => 10,
            'min_stock' => 1,
            'unit_cost' => 5000,
        ]);

        $this->postJson('/api/menu-items', [
            'name' => 'Ayam Geprek',
            'category_id' => $category->id,
            'price' => 22000,
            'is_available' => true,
            'recipe' => [
                [
                    'ingredient_id' => $ingredient->id,
                    'quantity' => 1.5,
                ],
            ],
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.cost', 7500);

        $this->assertDatabaseHas('menu_items', [
            'tenant_id' => $tenant->id,
            'name' => 'Ayam Geprek',
            'cost' => 0,
        ]);

        $this->getJson('/api/menu-items', [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.0.cost', 7500);
    }

    public function test_menu_item_update_ignores_legacy_cost_payload_and_returns_recipe_cost(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Menu Update', 'menu-update@example.com', 'password123', '1313');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '1313');

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Drink',
            'emoji' => '🥤',
        ]);

        $ingredient = Ingredient::create([
            'tenant_id' => $tenant->id,
            'name' => 'Syrup',
            'unit' => 'ml',
            'stock' => 100,
            'min_stock' => 5,
            'unit_cost' => 4000,
        ]);

        $menuItem = MenuItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Es Soda',
            'category_id' => $category->id,
            'legacy_category' => 'drink',
            'price' => 18000,
            'cost' => 99000,
            'is_available' => true,
        ]);

        MenuItemRecipe::create([
            'tenant_id' => $tenant->id,
            'menu_item_id' => $menuItem->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => 1,
        ]);

        $this->putJson("/api/menu-items/{$menuItem->id}", [
            'cost' => 12345,
            'recipe' => [
                [
                    'ingredient_id' => $ingredient->id,
                    'quantity' => 2,
                ],
            ],
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.cost', 8000);

        $menuItem->refresh();

        $this->assertSame(99000, $menuItem->cost);
        $this->assertSame(2.0, $menuItem->recipes()->first()->quantity);
    }

    public function test_menu_item_without_recipe_returns_zero_cost_even_if_legacy_cost_exists(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Menu Zero', 'menu-zero@example.com', 'password123', '1414');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '1414');

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Snack',
            'emoji' => '🍟',
        ]);

        $menuItem = MenuItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Keripik',
            'category_id' => $category->id,
            'legacy_category' => 'snack',
            'price' => 12000,
            'cost' => 6000,
            'is_available' => true,
        ]);

        $this->getJson('/api/menu-items', [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.0.id', $menuItem->id)
            ->assertJsonPath('data.0.cost', 0);
    }

    public function test_table_checkout_uses_recipe_derived_cost_for_order_snapshots(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Table Cost', 'table-cost@example.com', 'password123', '1515');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '1515');

        $table = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Table Cost',
            'type' => 'standard',
            'hourly_rate' => 20000,
        ]);

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Drink',
            'emoji' => '🥤',
        ]);

        $ingredient = Ingredient::create([
            'tenant_id' => $tenant->id,
            'name' => 'Kopi',
            'unit' => 'gram',
            'stock' => 20,
            'min_stock' => 1,
            'unit_cost' => 4500,
        ]);

        $menuItem = MenuItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Americano',
            'category_id' => $category->id,
            'legacy_category' => 'drink',
            'price' => 18000,
            'cost' => 99999,
            'is_available' => true,
        ]);

        MenuItemRecipe::create([
            'tenant_id' => $tenant->id,
            'menu_item_id' => $menuItem->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => 2,
        ]);

        $payment = PaymentOption::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cash',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $this->postJson('/api/cashier-shifts/open', [
            'opening_cash' => 100000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $this->postJson("/api/tables/{$table->id}/start-session", [
            'session_type' => 'billiard',
            'billing_mode' => 'open-bill',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $this->postJson("/api/tables/{$table->id}/add-order", [
            'menu_item_id' => $menuItem->id,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $checkout = $this->postJson("/api/tables/{$table->id}/checkout", [
            'payment_method_id' => $payment->id,
            'payment_method_name' => 'Cash',
            'cash_received' => 50000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.order_cost', 9000);

        $orderId = $checkout->json('data.id');

        $this->assertDatabaseHas('order_group_items', [
            'order_group_id' => OrderGroup::where('order_id', $orderId)->firstOrFail()->id,
            'menu_item_id' => $menuItem->id,
            'unit_cost' => 9000,
        ]);
    }

    public function test_open_bill_checkout_uses_recipe_derived_cost_for_order_snapshots(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Bill Cost', 'bill-cost@example.com', 'password123', '1616');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '1616');

        $table = Table::create([
            'tenant_id' => $tenant->id,
            'name' => 'Meja Bill Cost',
            'type' => 'standard',
            'hourly_rate' => 20000,
        ]);

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Food',
            'emoji' => '🍔',
        ]);

        $ingredient = Ingredient::create([
            'tenant_id' => $tenant->id,
            'name' => 'Patty',
            'unit' => 'pcs',
            'stock' => 20,
            'min_stock' => 1,
            'unit_cost' => 2500,
        ]);

        $menuItem = MenuItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Burger',
            'category_id' => $category->id,
            'legacy_category' => 'food',
            'price' => 22000,
            'cost' => 123,
            'is_available' => true,
        ]);

        MenuItemRecipe::create([
            'tenant_id' => $tenant->id,
            'menu_item_id' => $menuItem->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => 3,
        ]);

        $payment = PaymentOption::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cash',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $this->postJson('/api/cashier-shifts/open', [
            'opening_cash' => 100000,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $createBill = $this->postJson('/api/open-bills', [
            'table_id' => $table->id,
            'customer_name' => 'Recipe Guest',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201);

        $openBillId = $createBill->json('data.id');

        $this->postJson("/api/open-bills/{$openBillId}/add-item", [
            'fulfillment_type' => 'dine-in',
            'menu_item_id' => $menuItem->id,
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $checkout = $this->postJson("/api/open-bills/{$openBillId}/checkout", [
            'payment_method_id' => $payment->id,
            'payment_method_name' => 'Cash',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.order_cost', 7500);

        $orderId = $checkout->json('data.id');

        $this->assertDatabaseHas('order_group_items', [
            'order_group_id' => OrderGroup::where('order_id', $orderId)->firstOrFail()->id,
            'menu_item_id' => $menuItem->id,
            'unit_cost' => 7500,
        ]);
    }

    public function test_ingredient_store_uses_safe_defaults_and_exposes_lifecycle_metadata(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Ingredient Defaults', 'ingredient-defaults@example.com', 'password123', '1616');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '1616');

        $this->postJson('/api/ingredients', [
            'name' => 'Gula',
            'unit' => 'gram',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201)
            ->assertJsonPath('data.name', 'Gula')
            ->assertJsonPath('data.stock', 0)
            ->assertJsonPath('data.min_stock', 0)
            ->assertJsonPath('data.unit_cost', 0)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.recipe_usage_count', 0)
            ->assertJsonPath('data.stock_adjustment_count', 0)
            ->assertJsonPath('data.can_archive', false)
            ->assertJsonPath('data.can_delete', true);

        $this->assertDatabaseHas('ingredients', [
            'tenant_id' => $tenant->id,
            'name' => 'Gula',
            'is_active' => true,
        ]);
    }

    public function test_ingredient_store_rejects_duplicate_name_for_active_and_archived_records(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Ingredient Duplicate', 'ingredient-duplicate@example.com', 'password123', '1717');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '1717');

        $activeIngredient = Ingredient::create([
            'tenant_id' => $tenant->id,
            'name' => 'Tepung',
            'unit' => 'gram',
            'stock' => 12,
            'min_stock' => 2,
            'unit_cost' => 1000,
            'is_active' => true,
        ]);

        $this->postJson('/api/ingredients', [
            'name' => 'Tepung',
            'unit' => 'gram',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name'])
            ->assertJsonPath('errors.name.0', 'Nama bahan baku sudah digunakan.');

        StockAdjustment::create([
            'tenant_id' => $tenant->id,
            'ingredient_id' => $activeIngredient->id,
            'type' => 'in',
            'quantity' => 2,
            'reason' => 'Initial log',
            'adjusted_by' => 'Seeder',
            'previous_stock' => 12,
            'new_stock' => 14,
        ]);

        $this->patchJson("/api/ingredients/{$activeIngredient->id}/archive", [], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $this->postJson('/api/ingredients', [
            'name' => 'Tepung',
            'unit' => 'gram',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name'])
            ->assertJsonPath('errors.name.0', 'Nama bahan baku sudah digunakan.');
    }

    public function test_ingredient_delete_force_deletes_unused_record_and_allows_name_reuse(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Ingredient Delete', 'ingredient-delete@example.com', 'password123', '1818');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '1818');

        $ingredient = Ingredient::create([
            'tenant_id' => $tenant->id,
            'name' => 'Keju',
            'unit' => 'gram',
            'stock' => 3,
            'min_stock' => 1,
            'unit_cost' => 2500,
            'is_active' => true,
        ]);

        $this->deleteJson("/api/ingredients/{$ingredient->id}", [], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('message', 'Bahan dihapus permanen.');

        $this->assertDatabaseMissing('ingredients', [
            'id' => $ingredient->id,
        ]);

        $this->postJson('/api/ingredients', [
            'name' => 'Keju',
            'unit' => 'gram',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(201)
            ->assertJsonPath('data.name', 'Keju');
    }

    public function test_ingredient_delete_is_blocked_when_used_in_recipe(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Ingredient Recipe Guard', 'ingredient-recipe-guard@example.com', 'password123', '1919');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '1919');

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Food',
            'emoji' => '🍽️',
        ]);

        $ingredient = Ingredient::create([
            'tenant_id' => $tenant->id,
            'name' => 'Beras',
            'unit' => 'gram',
            'stock' => 1000,
            'min_stock' => 100,
            'unit_cost' => 15,
            'is_active' => true,
        ]);

        $menuItem = MenuItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Nasi Putih',
            'category_id' => $category->id,
            'legacy_category' => 'food',
            'price' => 10000,
            'cost' => 0,
            'is_available' => true,
        ]);

        MenuItemRecipe::create([
            'tenant_id' => $tenant->id,
            'menu_item_id' => $menuItem->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => 100,
        ]);

        $this->deleteJson("/api/ingredients/{$ingredient->id}", [], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['ingredient'])
            ->assertJsonPath('errors.ingredient.0', 'Bahan baku masih dipakai di recipe menu dan harus diarsipkan, bukan dihapus.');
    }

    public function test_ingredient_delete_is_blocked_when_stock_history_exists(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Ingredient History Guard', 'ingredient-history-guard@example.com', 'password123', '2020');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '2020');

        $ingredient = Ingredient::create([
            'tenant_id' => $tenant->id,
            'name' => 'Saus',
            'unit' => 'ml',
            'stock' => 10,
            'min_stock' => 1,
            'unit_cost' => 300,
            'is_active' => true,
        ]);

        StockAdjustment::create([
            'tenant_id' => $tenant->id,
            'ingredient_id' => $ingredient->id,
            'type' => 'in',
            'quantity' => 5,
            'reason' => 'Initial log',
            'adjusted_by' => 'Seeder',
            'previous_stock' => 10,
            'new_stock' => 15,
        ]);

        $this->deleteJson("/api/ingredients/{$ingredient->id}", [], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['ingredient'])
            ->assertJsonPath('errors.ingredient.0', 'Bahan baku punya histori stok dan tidak bisa dihapus permanen. Arsipkan bahan ini.');
    }

    public function test_ingredient_can_be_archived_and_restored_without_breaking_recipe_references(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Ingredient Archive', 'ingredient-archive@example.com', 'password123', '2121');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '2121');

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Drink',
            'emoji' => '🥤',
        ]);

        $ingredient = Ingredient::create([
            'tenant_id' => $tenant->id,
            'name' => 'Sirup',
            'unit' => 'ml',
            'stock' => 20,
            'min_stock' => 1,
            'unit_cost' => 200,
            'is_active' => true,
        ]);

        $menuItem = MenuItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Es Sirup',
            'category_id' => $category->id,
            'legacy_category' => 'drink',
            'price' => 12000,
            'cost' => 0,
            'is_available' => true,
        ]);

        MenuItemRecipe::create([
            'tenant_id' => $tenant->id,
            'menu_item_id' => $menuItem->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => 2.5,
        ]);

        $this->patchJson("/api/ingredients/{$ingredient->id}/archive", [], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('message', 'Bahan baku diarsipkan. Recipe lama tetap tersimpan, tetapi bahan ini tidak bisa dipakai untuk recipe baru.')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.can_archive', false)
            ->assertJsonPath('data.can_delete', false);

        $this->getJson("/api/menu-items/{$menuItem->id}", [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.cost', 500)
            ->assertJsonPath('data.recipes.0.ingredient.name', 'Sirup')
            ->assertJsonPath('data.recipes.0.ingredient.unit', 'ml')
            ->assertJsonPath('data.recipes.0.ingredient.unit_cost', 200);

        $this->patchJson("/api/ingredients/{$ingredient->id}/restore", [], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('message', 'Bahan baku dipulihkan dan bisa dipakai kembali.')
            ->assertJsonPath('data.is_active', true);
    }

    public function test_menu_item_store_and_update_reject_archived_and_soft_deleted_ingredients(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Menu Ingredient Guard', 'menu-ingredient-guard@example.com', 'password123', '2222');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '2222');

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Food',
            'emoji' => '🍽️',
        ]);

        $archivedIngredient = Ingredient::create([
            'tenant_id' => $tenant->id,
            'name' => 'Telur',
            'unit' => 'pcs',
            'stock' => 12,
            'min_stock' => 2,
            'unit_cost' => 1800,
            'is_active' => false,
        ]);

        $softDeletedIngredient = Ingredient::create([
            'tenant_id' => $tenant->id,
            'name' => 'Mentega',
            'unit' => 'gram',
            'stock' => 5,
            'min_stock' => 1,
            'unit_cost' => 900,
            'is_active' => true,
        ]);
        $softDeletedIngredient->delete();

        $this->postJson('/api/menu-items', [
            'name' => 'Telur Dadar',
            'category_id' => $category->id,
            'price' => 14000,
            'is_available' => true,
            'recipe' => [
                [
                    'ingredient_id' => $archivedIngredient->id,
                    'quantity' => 2,
                ],
            ],
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['recipe.0.ingredient_id']);

        $menuItem = MenuItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Roti Bakar',
            'category_id' => $category->id,
            'legacy_category' => 'food',
            'price' => 15000,
            'cost' => 0,
            'is_available' => true,
        ]);

        $this->putJson("/api/menu-items/{$menuItem->id}", [
            'recipe' => [
                [
                    'ingredient_id' => $softDeletedIngredient->id,
                    'quantity' => 1,
                ],
            ],
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['recipe.0.ingredient_id']);
    }

    public function test_menu_item_index_keeps_legacy_soft_deleted_ingredient_visible_in_recipe_and_cost(): void
    {
        [$tenant, $admin] = $this->createTenantWithAdmin('Tenant Menu Legacy Ingredient', 'menu-legacy-ingredient@example.com', 'password123', '2323');
        $staffToken = $this->loginAsStaff($tenant, $admin, 'password123', '2323');

        $category = MenuCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Drink',
            'emoji' => '🥤',
        ]);

        $ingredient = Ingredient::create([
            'tenant_id' => $tenant->id,
            'name' => 'Kopi Bubuk',
            'unit' => 'gram',
            'stock' => 100,
            'min_stock' => 10,
            'unit_cost' => 120,
            'is_active' => true,
        ]);

        $menuItem = MenuItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Kopi Tubruk',
            'category_id' => $category->id,
            'legacy_category' => 'drink',
            'price' => 16000,
            'cost' => 0,
            'is_available' => true,
        ]);

        MenuItemRecipe::create([
            'tenant_id' => $tenant->id,
            'menu_item_id' => $menuItem->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => 10,
        ]);

        $ingredient->delete();

        $this->getJson('/api/menu-items', [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()
            ->assertJsonPath('data.0.id', $menuItem->id)
            ->assertJsonPath('data.0.cost', 1200)
            ->assertJsonPath('data.0.recipes.0.ingredient.name', 'Kopi Bubuk')
            ->assertJsonPath('data.0.recipes.0.ingredient.unit', 'gram')
            ->assertJsonPath('data.0.recipes.0.ingredient.unit_cost', 120);
    }
}
