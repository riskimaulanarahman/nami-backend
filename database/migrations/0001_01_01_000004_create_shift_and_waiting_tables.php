<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_shifts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('staff_id');
            $table->string('staff_name')->default('');
            $table->string('status')->default('active');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('opening_cash')->default(0);
            $table->unsignedInteger('closing_cash')->nullable();
            $table->unsignedInteger('expected_cash')->default(0);
            $table->integer('variance_cash')->nullable();
            $table->unsignedInteger('cash_sales')->default(0);
            $table->unsignedInteger('cash_refunds')->default(0);
            $table->unsignedInteger('non_cash_sales')->default(0);
            $table->unsignedInteger('non_cash_refunds')->default(0);
            $table->unsignedInteger('transaction_count')->default(0);
            $table->unsignedInteger('refund_count')->default(0);
            $table->text('note')->nullable();
            $table->boolean('is_legacy')->default(false);
            $table->timestamps();
            $table->foreign(['tenant_id', 'staff_id'])->references(['tenant_id', 'id'])->on('staff')->cascadeOnDelete();
            $table->index('tenant_id');
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('cashier_shift_involved_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('cashier_shift_id');
            $table->ulid('staff_id');
            $table->string('staff_name');
            $table->timestamps();
            $table->foreign(['tenant_id', 'cashier_shift_id'])->references(['tenant_id', 'id'])->on('cashier_shifts')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'staff_id'])->references(['tenant_id', 'id'])->on('staff')->cascadeOnDelete();
            $table->unique(['tenant_id', 'cashier_shift_id', 'staff_id'], 'csis_tenant_shift_staff_unique');
        });

        Schema::create('waiting_list_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('customer_name');
            $table->string('phone')->default('');
            $table->unsignedSmallInteger('party_size')->default(1);
            $table->text('notes')->nullable();
            $table->string('preferred_table_type')->default('any');
            $table->string('status')->default('waiting');
            $table->timestamp('seated_at')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'table_id'])->references(['tenant_id', 'id'])->on('tables')->cascadeOnDelete();
            $table->index('tenant_id');
            $table->unique(['tenant_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waiting_list_entries');
        Schema::dropIfExists('cashier_shift_involved_staff');
        Schema::dropIfExists('cashier_shifts');
    }
};
