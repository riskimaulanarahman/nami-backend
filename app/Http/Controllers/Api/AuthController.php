<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StaffResource;
use App\Models\Staff;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function tenantLogin(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $tenant = Tenant::where('email', strtolower($data['email']))->first();
        if (!$tenant || !Hash::check($data['password'], $tenant->password)) {
            return response()->json(['message' => 'Email atau password tenant salah.'], 401);
        }

        if (!$tenant->is_active) {
            return response()->json(['message' => 'Akun tenant tidak aktif.'], 403);
        }

        $token = $tenant->createToken('tenant-token')->plainTextToken;

        return response()->json([
            'data' => [
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'email' => $tenant->email,
                    'plan' => $tenant->plan,
                ],
                'token' => $token,
            ],
        ]);
    }

    public function staffList(Request $request)
    {
        /** @var Tenant $tenant */
        $tenant = $request->user();

        $staff = Staff::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->get(['id', 'name', 'username', 'role', 'avatar', 'is_active']);

        return response()->json(['data' => $staff]);
    }

    public function staffPinLogin(Request $request)
    {
        $data = $request->validate([
            'staff_id' => ['required', 'string'],
            'pin' => ['required', 'string', 'min:4'],
        ]);

        /** @var Tenant $tenant */
        $tenant = $request->user();

        $staff = Staff::where('tenant_id', $tenant->id)->find($data['staff_id']);
        if (!$staff || !Hash::check($data['pin'], $staff->pin)) {
            return response()->json(['message' => 'PIN salah atau staff tidak ditemukan.'], 401);
        }

        if (!$staff->is_active) {
            return response()->json(['message' => 'Akun staff tidak aktif.'], 403);
        }

        $token = $staff->createToken('staff-pos-token')->plainTextToken;

        return response()->json([
            'data' => [
                'staff' => new StaffResource($staff),
                'token' => $token,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request)
    {
        return new StaffResource($request->user());
    }

    public function verifyAdminPin(Request $request)
    {
        $request->validate(['pin' => 'required|string']);
        $tenantId = $request->user()?->tenant_id;

        $admins = Staff::where('tenant_id', $tenantId)
            ->where('role', 'admin')
            ->where('is_active', true)
            ->get();

        $valid = $admins->contains(fn ($admin) => Hash::check($request->pin, $admin->pin));

        return response()->json(['data' => ['valid' => $valid]]);
    }

    public function verifyStaffPin(Request $request)
    {
        $request->validate([
            'staff_id' => 'required|string',
            'pin' => 'required|string',
        ]);

        $tenantId = $request->user()?->tenant_id;
        $staff = Staff::where('tenant_id', $tenantId)->find($request->staff_id);
        $valid = $staff && Hash::check($request->pin, $staff->pin);

        return response()->json(['data' => ['valid' => $valid]]);
    }
}

