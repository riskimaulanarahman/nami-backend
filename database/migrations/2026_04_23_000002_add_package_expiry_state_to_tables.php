<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            $table->timestamp('package_expired_at')
                ->nullable()
                ->after('package_total_price');
            $table->timestamp('overrun_started_at')
                ->nullable()
                ->after('package_expired_at');
            $table->unsignedInteger('accrued_overrun_cost')
                ->default(0)
                ->after('overrun_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            $table->dropColumn([
                'package_expired_at',
                'overrun_started_at',
                'accrued_overrun_cost',
            ]);
        });
    }
};
