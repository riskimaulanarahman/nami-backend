<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            $table->unsignedInteger('package_minutes_total')
                ->default(0)
                ->after('selected_package_price');
            $table->unsignedInteger('package_total_price')
                ->default(0)
                ->after('package_minutes_total');
        });
    }

    public function down(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            $table->dropColumn([
                'package_minutes_total',
                'package_total_price',
            ]);
        });
    }
};
