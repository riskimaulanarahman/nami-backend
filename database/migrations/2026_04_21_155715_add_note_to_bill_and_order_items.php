<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('open_bill_group_items', function (Blueprint $table) {
            $table->string('note')->nullable()->after('added_at');
        });

        Schema::table('order_group_items', function (Blueprint $table) {
            $table->string('note')->nullable()->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('open_bill_group_items', function (Blueprint $table) {
            $table->dropColumn('note');
        });

        Schema::table('order_group_items', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
