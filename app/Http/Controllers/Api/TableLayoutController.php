<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TableLayoutPosition;
use App\Models\Table;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TableLayoutController extends Controller
{
    public function index()
    {
        $positions = TableLayoutPosition::all()->keyBy('table_id');
        return response()->json(['data' => $positions]);
    }

    public function update(Request $request, int $tableId)
    {
        $tenantId = $request->user()?->tenant_id;
        $data = $request->validate([
            'x_percent' => 'sometimes|numeric|min:0|max:100',
            'y_percent' => 'sometimes|numeric|min:0|max:100',
            'width_percent' => 'sometimes|numeric|min:1|max:100',
        ]);
        validator(
            ['table_id' => $tableId],
            ['table_id' => ['required', Rule::exists('tables', 'id')->where('tenant_id', $tenantId)]]
        )->validate();

        $position = TableLayoutPosition::updateOrCreate(
            ['table_id' => $tableId],
            $data,
        );

        return response()->json(['data' => $position]);
    }

    public function reset()
    {
        $tables = Table::all();
        $defaults = [
            1 => ['x_percent' => 8, 'y_percent' => 14, 'width_percent' => 26],
            2 => ['x_percent' => 37, 'y_percent' => 14, 'width_percent' => 26],
            3 => ['x_percent' => 66, 'y_percent' => 14, 'width_percent' => 26],
            4 => ['x_percent' => 8, 'y_percent' => 36, 'width_percent' => 26],
            5 => ['x_percent' => 37, 'y_percent' => 36, 'width_percent' => 26],
        ];

        foreach ($tables as $table) {
            $pos = $defaults[$table->id] ?? ['x_percent' => 8, 'y_percent' => 14, 'width_percent' => 26];
            TableLayoutPosition::updateOrCreate(['table_id' => $table->id], $pos);
        }

        return response()->json(['data' => TableLayoutPosition::all()->keyBy('table_id')]);
    }
}
