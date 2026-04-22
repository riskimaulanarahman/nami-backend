<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class MemberController extends Controller
{
    public function index(Request $request)
    {
        $query = Member::orderByDesc('created_at');
        if ($request->has('search')) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$request->search}%")
                ->orWhere('code', 'like', "%{$request->search}%")
                ->orWhere('phone', 'like', "%{$request->search}%")
            );
        }
        return response()->json(['data' => $query->paginate(50)]);
    }

    public function store(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:20', Rule::unique('members', 'code')->where('tenant_id', $tenantId)],
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:30',
        ]);

        if (empty($data['code'])) {
            $data['code'] = $this->generateMemberCode();
        }
        $data['phone'] = $data['phone'] ?? '';

        return response()->json(['data' => Member::create($data)], 201);
    }

    public function show(Member $member)
    {
        return response()->json(['data' => $member]);
    }

    public function update(Request $request, Member $member)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:30',
        ]);
        if (array_key_exists('phone', $data) && $data['phone'] === null) {
            $data['phone'] = '';
        }
        $member->update($data);
        return response()->json(['data' => $member->fresh()]);
    }

    public function destroy(Member $member)
    {
        $member->delete();
        return response()->json(['message' => 'Member dihapus.']);
    }

    public function points(Member $member)
    {
        return response()->json([
            'data' => $member->pointLedger()->orderByDesc('created_at')->get(),
        ]);
    }

    private function generateMemberCode(): string
    {
        do {
            $code = sprintf(
                'MBR-%s-%s',
                now()->format('ymd'),
                Str::upper(Str::random(4)),
            );
        } while (Member::query()->withoutGlobalScopes()->withTrashed()->where('code', $code)->exists());

        return $code;
    }
}
