<?php

use App\Models\PaymentOption;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_options', function (Blueprint $table) {
            $table->boolean('is_system_default')->default(false)->after('is_group');
        });

        $now = now();
        $defaults = PaymentOption::SYSTEM_DEFAULTS;
        $tenantIds = DB::table('tenants')->pluck('id');

        foreach ($tenantIds as $tenantId) {
            foreach ($defaults as $type => $definition) {
                $existing = DB::table('payment_options')
                    ->where('tenant_id', $tenantId)
                    ->where('type', $type)
                    ->whereNull('parent_id')
                    ->orderBy('sort_order')
                    ->orderBy('created_at')
                    ->first();

                $payload = [
                    'name' => $definition['name'],
                    'icon' => $definition['icon'],
                    'is_active' => true,
                    'requires_reference' => false,
                    'reference_label' => '',
                    'parent_id' => null,
                    'is_group' => false,
                    'sort_order' => $definition['sort_order'],
                    'is_system_default' => true,
                    'deleted_at' => null,
                    'updated_at' => $now,
                ];

                if ($existing) {
                    DB::table('payment_options')
                        ->where('id', $existing->id)
                        ->update($payload);
                    continue;
                }

                DB::table('payment_options')->insert([
                    'id' => (string) Str::ulid(),
                    'tenant_id' => $tenantId,
                    'name' => $definition['name'],
                    'type' => $type,
                    'icon' => $definition['icon'],
                    'is_active' => true,
                    'requires_reference' => false,
                    'reference_label' => '',
                    'parent_id' => null,
                    'is_group' => false,
                    'sort_order' => $definition['sort_order'],
                    'is_system_default' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ]);
            }

            DB::table('payment_options')
                ->where('tenant_id', $tenantId)
                ->whereNull('parent_id')
                ->where('is_system_default', false)
                ->update([
                    'is_active' => false,
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('payment_options', function (Blueprint $table) {
            $table->dropColumn('is_system_default');
        });
    }
};
