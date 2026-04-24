<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessSettings;
use App\Models\Staff;
use App\Models\Tenant;
use App\Services\PaymentOptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantController extends Controller
{
    public function __construct(private PaymentOptionService $paymentOptionService) {}

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:tenants,email'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_pin' => ['required', 'string', 'min:4', 'max:12'],
        ]);

        $tenant = DB::transaction(function () use ($data) {
            $tenant = Tenant::create([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'password' => $data['password'],
                'plan' => 'free',
                'is_active' => true,
            ]);

            BusinessSettings::create([
                'tenant_id' => $tenant->id,
                'name' => $data['name'],
                'address' => '',
                'phone' => '',
                'tax_percent' => 0,
                'paper_size' => '58mm',
                'footer_message' => 'Terima kasih atas kunjungan Anda.',
            ]);

            Staff::create([
                'tenant_id' => $tenant->id,
                'name' => $data['admin_name'],
                'username' => 'admin-' . Str::lower(Str::random(6)),
                'pin' => $data['admin_pin'],
                'role' => 'admin',
                'avatar' => 'AD',
                'is_active' => true,
            ]);

            $this->paymentOptionService->ensureSystemDefaultsForTenant($tenant->id);

            return $tenant;
        });

        return response()->json([
            'message' => 'Tenant registered successfully',
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'email' => $tenant->email,
                'plan' => $tenant->plan,
            ],
        ], 201);
    }
}
