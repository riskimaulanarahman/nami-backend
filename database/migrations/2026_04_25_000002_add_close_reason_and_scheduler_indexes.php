<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('open_bills', function (Blueprint $table) {
            $table->string('close_reason')
                ->nullable()
                ->after('locked_final');
            $table->index(
                ['tenant_id', 'status', 'locked_final', 'close_reason', 'created_at'],
                'open_bills_draft_lookup_idx',
            );
        });

        Schema::table('tables', function (Blueprint $table) {
            $table->index(
                ['status', 'session_type', 'billing_mode', 'package_expired_at', 'id'],
                'tables_package_expiry_lookup_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            $table->dropIndex('tables_package_expiry_lookup_idx');
        });

        Schema::table('open_bills', function (Blueprint $table) {
            $table->dropIndex('open_bills_draft_lookup_idx');
            $table->dropColumn('close_reason');
        });
    }
};
