<?php

namespace App\Http\Controllers\Api;

use App\Enums\TableStatus;
use App\Enums\WaitingListStatus;
use App\Http\Controllers\Controller;
use App\Models\Table;
use App\Models\WaitingListEntry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WaitingListController extends Controller
{
    public function index(Request $request)
    {
        $query = WaitingListEntry::orderByDesc('created_at');
        if ($request->has('status')) $query->where('status', $request->status);
        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'party_size' => 'integer|min:1',
            'notes' => 'nullable|string',
            'preferred_table_type' => 'nullable|in:any,standard,vip',
        ]);

        $entry = WaitingListEntry::create(array_merge($data, ['status' => WaitingListStatus::Waiting]));
        return response()->json(['data' => $entry], 201);
    }

    public function update(Request $request, WaitingListEntry $waitingListEntry)
    {
        $data = $request->validate([
            'customer_name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:30',
            'party_size' => 'sometimes|integer|min:1',
            'notes' => 'nullable|string',
            'preferred_table_type' => 'nullable|in:any,standard,vip',
        ]);
        $waitingListEntry->update($data);
        return response()->json(['data' => $waitingListEntry->fresh()]);
    }

    public function seat(Request $request, WaitingListEntry $waitingListEntry)
    {
        $tenantId = $request->user()?->tenant_id;
        $data = $request->validate([
            'table_id' => ['required', Rule::exists('tables', 'id')->where('tenant_id', $tenantId)],
        ]);
        $table = Table::findOrFail($data['table_id']);

        if ($table->status !== TableStatus::Available) {
            return response()->json(['message' => 'Meja tidak tersedia.'], 422);
        }

        $waitingListEntry->update([
            'status' => WaitingListStatus::Seated,
            'seated_at' => now(),
            'table_id' => $table->id,
        ]);
        $table->update(['status' => TableStatus::Reserved]);

        return response()->json(['data' => $waitingListEntry->fresh()]);
    }

    public function cancel(WaitingListEntry $waitingListEntry)
    {
        if ($waitingListEntry->table_id) {
            Table::where('id', $waitingListEntry->table_id)
                ->where('status', TableStatus::Reserved)
                ->update(['status' => TableStatus::Available]);
        }
        $waitingListEntry->update(['status' => WaitingListStatus::Cancelled]);
        return response()->json(['data' => $waitingListEntry->fresh()]);
    }
}
