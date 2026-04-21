<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    public function index()
    {
        return response()->json(['data' => Staff::orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => ['required', 'string', 'max:255', Rule::unique('staff', 'username')->where('tenant_id', $tenantId)],
            'pin' => 'required|string|min:4|max:10',
            'role' => 'required|in:admin,kasir',
            'avatar' => 'nullable|string|max:10',
            'is_active' => 'boolean',
        ]);

        $staff = Staff::create($data);
        return response()->json(['data' => $staff], 201);
    }

    public function show(Staff $staff)
    {
        return response()->json(['data' => $staff]);
    }

    public function update(Request $request, Staff $staff)
    {
        $tenantId = $request->user()?->tenant_id;

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'username' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('staff', 'username')->where('tenant_id', $tenantId)->ignore($staff->id),
            ],
            'pin' => 'sometimes|string|min:4|max:10',
            'role' => 'sometimes|in:admin,kasir',
            'avatar' => 'nullable|string|max:10',
            'is_active' => 'sometimes|boolean',
        ]);

        $staff->update($data);
        return response()->json(['data' => $staff->fresh()]);
    }

    public function destroy(Staff $staff)
    {
        $staff->delete();
        return response()->json(['message' => 'Staff dihapus.']);
    }
}
