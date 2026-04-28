<?php

namespace Tests\Feature;

use App\Models\PaymentOption;
use App\Models\Staff;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function createTenantWithStaff(string $name, string $email, string $password, string $pin = '123456'): array
    {
        $tenant = Tenant::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'plan' => 'free',
            'is_active' => true,
        ]);

        $staff = Staff::create([
            'tenant_id' => $tenant->id,
            'name' => $name . ' Admin',
            'username' => strtolower(str_replace(' ', '-', $name)) . '-admin',
            'pin' => $pin,
            'role' => 'admin',
            'avatar' => 'AD',
            'is_active' => true,
        ]);

        return [$tenant, $staff];
    }

    public function test_tenant_login_and_staff_list_are_scoped_to_same_tenant(): void
    {
        [$tenantA, $staffA] = $this->createTenantWithStaff('Tenant A', 'a@example.com', 'password123', '111111');
        [$tenantB] = $this->createTenantWithStaff('Tenant B', 'b@example.com', 'password123', '222222');

        Staff::create([
            'tenant_id' => $tenantA->id,
            'name' => 'Kasir A',
            'username' => 'kasir-a',
            'pin' => '333333',
            'role' => 'kasir',
            'avatar' => 'KA',
            'is_active' => true,
        ]);

        Staff::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Kasir B',
            'username' => 'kasir-b',
            'pin' => '444444',
            'role' => 'kasir',
            'avatar' => 'KB',
            'is_active' => true,
        ]);

        $login = $this->postJson('/api/auth/tenant-login', [
            'email' => $tenantA->email,
            'password' => 'password123',
        ])->assertOk();

        $tenantToken = $login->json('data.token');
        $this->assertNotEmpty($tenantToken);

        $listResponse = $this->getJson('/api/auth/staff-list', [
            'Authorization' => "Bearer {$tenantToken}",
        ])->assertOk();

        $staffIds = collect($listResponse->json('data'))->pluck('id')->all();
        $this->assertContains($staffA->id, $staffIds);
        $this->assertCount(2, $staffIds);
    }

    public function test_tenant_login_rejects_invalid_password(): void
    {
        $this->createTenantWithStaff('Tenant A', 'a@example.com', 'password123');

        $this->postJson('/api/auth/tenant-login', [
            'email' => 'a@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_staff_pin_login_accepts_valid_six_digit_pin(): void
    {
        [$tenant, $staff] = $this->createTenantWithStaff('Tenant Strict', 'strict@example.com', 'password123', '123456');

        $tenantLogin = $this->postJson('/api/auth/tenant-login', [
            'email' => $tenant->email,
            'password' => 'password123',
        ])->assertOk();
        $tenantToken = $tenantLogin->json('data.token');

        $this->postJson('/api/auth/staff-pin-login', [
            'staff_id' => $staff->id,
            'pin' => '123456',
        ], [
            'Authorization' => "Bearer {$tenantToken}",
        ])->assertOk();
    }

    public function test_staff_pin_login_requires_same_tenant_context(): void
    {
        [$tenantA, $staffA] = $this->createTenantWithStaff('Tenant A', 'a@example.com', 'password123', '123456');
        [, $staffB] = $this->createTenantWithStaff('Tenant B', 'b@example.com', 'password123', '567856');

        $tenantLogin = $this->postJson('/api/auth/tenant-login', [
            'email' => $tenantA->email,
            'password' => 'password123',
        ])->assertOk();
        $tenantToken = $tenantLogin->json('data.token');

        // Staff from another tenant must be rejected.
        $this->postJson('/api/auth/staff-pin-login', [
            'staff_id' => $staffB->id,
            'pin' => '567856',
        ], [
            'Authorization' => "Bearer {$tenantToken}",
        ])->assertStatus(401);

        // Wrong PIN in same tenant must be rejected.
        $this->postJson('/api/auth/staff-pin-login', [
            'staff_id' => $staffA->id,
            'pin' => '000000',
        ], [
            'Authorization' => "Bearer {$tenantToken}",
        ])->assertStatus(401);

        // Correct PIN in same tenant must pass.
        $staffLogin = $this->postJson('/api/auth/staff-pin-login', [
            'staff_id' => $staffA->id,
            'pin' => '123456',
        ], [
            'Authorization' => "Bearer {$tenantToken}",
        ])->assertOk();

        $staffToken = $staffLogin->json('data.token');
        $this->assertNotEmpty($staffToken);

        $this->getJson('/api/auth/me', [
            'Authorization' => "Bearer {$staffToken}",
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $staffA->id);
    }

    public function test_staff_pin_login_rejects_pin_lengths_outside_six_digit_rule(): void
    {
        [$tenant, $staff] = $this->createTenantWithStaff('Tenant A', 'a@example.com', 'password123', '123456');

        $tenantLogin = $this->postJson('/api/auth/tenant-login', [
            'email' => $tenant->email,
            'password' => 'password123',
        ])->assertOk();
        $tenantToken = $tenantLogin->json('data.token');

        foreach (['12345', '1234567', '12ab'] as $pin) {
            $this->postJson('/api/auth/staff-pin-login', [
                'staff_id' => $staff->id,
                'pin' => $pin,
            ], [
                'Authorization' => "Bearer {$tenantToken}",
            ])->assertStatus(422);
        }
    }

    public function test_staff_crud_requires_new_pins_to_be_exactly_six_digits(): void
    {
        [$tenant, $admin] = $this->createTenantWithStaff('Tenant Staff', 'staff@example.com', 'password123', '123456');
        $staffToken = $this->postJson('/api/auth/tenant-login', [
            'email' => $tenant->email,
            'password' => 'password123',
        ])->assertOk()->json('data.token');

        $adminToken = $this->postJson('/api/auth/staff-pin-login', [
            'staff_id' => $admin->id,
            'pin' => '123456',
        ], [
            'Authorization' => "Bearer {$staffToken}",
        ])->assertOk()->json('data.token');

        $this->postJson('/api/staff', [
            'name' => 'Kasir Baru',
            'username' => 'kasir-baru',
            'pin' => '12345',
            'role' => 'kasir',
        ], [
            'Authorization' => "Bearer {$adminToken}",
        ])->assertStatus(422);

        $createResponse = $this->postJson('/api/staff', [
            'name' => 'Kasir Baru',
            'username' => 'kasir-baru',
            'pin' => '654321',
            'role' => 'kasir',
        ], [
            'Authorization' => "Bearer {$adminToken}",
        ])->assertStatus(201);

        $staffId = $createResponse->json('data.id');

        $this->putJson("/api/staff/{$staffId}", [
            'pin' => '99999',
        ], [
            'Authorization' => "Bearer {$adminToken}",
        ])->assertStatus(422);

        $this->putJson("/api/staff/{$staffId}", [
            'pin' => '999999',
        ], [
            'Authorization' => "Bearer {$adminToken}",
        ])->assertOk();
    }

    public function test_tenant_register_creates_system_default_payment_parents(): void
    {
        $response = $this->postJson('/api/tenants/register', [
            'name' => 'Tenant Payment Defaults',
            'email' => 'defaults@example.com',
            'password' => 'password123',
            'admin_name' => 'Owner',
            'admin_pin' => '123456',
        ])->assertStatus(201);

        $tenantId = $response->json('tenant.id');
        $defaults = PaymentOption::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('is_system_default', true)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(3, $defaults);
        $this->assertSame(['Cash', 'QRIS', 'Transfer'], $defaults->pluck('name')->all());
        $this->assertSame(['cash', 'qris', 'transfer'], $defaults->map(fn (PaymentOption $option) => $option->type->value)->all());
        $this->assertTrue($defaults->every(fn (PaymentOption $option) => $option->parent_id === null && $option->is_active));
    }
}
