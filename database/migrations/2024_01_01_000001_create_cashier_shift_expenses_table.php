<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_shift_expenses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('cashier_shift_id');
            $table->ulid('staff_id');
            $table->string('staff_name');
            $table->unsignedInteger('amount');
            $table->string('description');
            $table->string('category')->default('other');
            $table->string('delete_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('cashier_shift_id')->references('id')->on('cashier_shifts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_shift_expenses');
    }
};
