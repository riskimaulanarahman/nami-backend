<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function createTenantWithStaff(string $name, string $email, string $password, string $pin = '1234'): array
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
        [$tenantA, $staffA] = $this->createTenantWithStaff('Tenant A', 'a@example.com', 'password123', '1111');
        [$tenantB] = $this->createTenantWithStaff('Tenant B', 'b@example.com', 'password123', '2222');

        Staff::create([
            'tenant_id' => $tenantA->id,
            'name' => 'Kasir A',
            'username' => 'kasir-a',
            'pin' => '3333',
            'role' => 'kasir',
            'avatar' => 'KA',
            'is_active' => true,
        ]);

        Staff::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Kasir B',
            'username' => 'kasir-b',
            'pin' => '4444',
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

    public function test_staff_pin_login_requires_same_tenant_context(): void
    {
        [$tenantA, $staffA] = $this->createTenantWithStaff('Tenant A', 'a@example.com', 'password123', '1234');
        [, $staffB] = $this->createTenantWithStaff('Tenant B', 'b@example.com', 'password123', '5678');

        $tenantLogin = $this->postJson('/api/auth/tenant-login', [
            'email' => $tenantA->email,
            'password' => 'password123',
        ])->assertOk();
        $tenantToken = $tenantLogin->json('data.token');

        // Staff from another tenant must be rejected.
        $this->postJson('/api/auth/staff-pin-login', [
            'staff_id' => $staffB->id,
            'pin' => '5678',
        ], [
            'Authorization' => "Bearer {$tenantToken}",
        ])->assertStatus(401);

        // Wrong PIN in same tenant must be rejected.
        $this->postJson('/api/auth/staff-pin-login', [
            'staff_id' => $staffA->id,
            'pin' => '0000',
        ], [
            'Authorization' => "Bearer {$tenantToken}",
        ])->assertStatus(401);

        // Correct PIN in same tenant must pass.
        $staffLogin = $this->postJson('/api/auth/staff-pin-login', [
            'staff_id' => $staffA->id,
            'pin' => '1234',
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
}
