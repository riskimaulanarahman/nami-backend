<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('open_bills', function (Blueprint $table) {
            $table->unsignedBigInteger('source_table_id')->nullable()->after('origin_staff_name');
            $table->string('source_table_name')->nullable()->after('source_table_id');
            $table->string('source_table_type')->nullable()->after('source_table_name');
            $table->string('session_type')->nullable()->after('source_table_type');
            $table->string('billing_mode')->nullable()->after('session_type');
            $table->timestamp('session_started_at')->nullable()->after('billing_mode');
            $table->timestamp('session_ended_at')->nullable()->after('session_started_at');
            $table->unsignedInteger('duration_minutes')->default(0)->after('session_ended_at');
            $table->string('session_charge_name')->nullable()->after('duration_minutes');
            $table->unsignedInteger('session_charge_total')->default(0)->after('session_charge_name');
            $table->string('selected_package_name')->nullable()->after('session_charge_total');
            $table->unsignedSmallInteger('selected_package_hours')->default(0)->after('selected_package_name');
            $table->unsignedInteger('selected_package_price')->default(0)->after('selected_package_hours');
            $table->boolean('locked_final')->default(false)->after('selected_package_price');

            $table->foreign(['tenant_id', 'source_table_id'])
                ->references(['tenant_id', 'id'])
                ->on('tables')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('open_bills', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'source_table_id']);
            $table->dropColumn([
                'source_table_id',
                'source_table_name',
                'source_table_type',
                'session_type',
                'billing_mode',
                'session_started_at',
                'session_ended_at',
                'duration_minutes',
                'session_charge_name',
                'session_charge_total',
                'selected_package_name',
                'selected_package_hours',
                'selected_package_price',
                'locked_final',
            ]);
        });
    }
};
