<?php

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\OpenBill;
use App\Models\PaymentOption;
use App\Models\Staff;
use App\Models\Table;
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
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $checkout
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.payment_method_id', $payment->id);

        $this->assertGreaterThanOrEqual(10000, (int) $checkout->json('data.grand_total'));

        // 5. Verify transaction is attached to active shift
        $transactions = $this->getJson("/api/cashier-shifts/{$shiftId}/transactions", [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk();

        $this->assertNotEmpty($transactions->json('data'));
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
}
