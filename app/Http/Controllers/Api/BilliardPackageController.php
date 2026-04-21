<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BilliardPackage;
use Illuminate\Http\Request;

class BilliardPackageController extends Controller
{
    public function index(Request $request)
    {
        $query = BilliardPackage::orderBy('sort_order');
        if ($request->has('active_only')) $query->where('is_active', true);
        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'duration_hours' => 'required|integer|min:1',
            'price' => 'required|integer|min:0',
            'is_active' => 'boolean',
        ]);
        return response()->json(['data' => BilliardPackage::create($data)], 201);
    }

    public function update(Request $request, BilliardPackage $billiardPackage)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'duration_hours' => 'sometimes|integer|min:1',
            'price' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);
        $billiardPackage->update($data);
        return response()->json(['data' => $billiardPackage->fresh()]);
    }

    public function destroy(BilliardPackage $billiardPackage)
    {
        $billiardPackage->delete();
        return response()->json(['message' => 'Paket dihapus.']);
    }
}
