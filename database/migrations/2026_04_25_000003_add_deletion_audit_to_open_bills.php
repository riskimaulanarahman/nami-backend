<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('open_bills', function (Blueprint $table) {
            $table->string('delete_reason')->nullable()->after('close_reason');
            $table->string('deleted_by_staff_id')->nullable()->after('delete_reason');
            $table->string('deleted_by_staff_name')->nullable()->after('deleted_by_staff_id');
            $table->softDeletes();
            $table->index(
                ['tenant_id', 'deleted_at', 'status'],
                'open_bills_deleted_report_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('open_bills', function (Blueprint $table) {
            $table->dropIndex('open_bills_deleted_report_idx');
            $table->dropSoftDeletes();
            $table->dropColumn([
                'delete_reason',
                'deleted_by_staff_id',
                'deleted_by_staff_name',
            ]);
        });
    }
};
