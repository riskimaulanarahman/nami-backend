<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('standard');
            $table->string('status')->default('available');
            $table->unsignedInteger('hourly_rate')->default(25000);
            $table->timestamp('start_time')->nullable();
            $table->string('session_type')->nullable();
            $table->string('billing_mode')->nullable();
            $table->string('active_open_bill_id')->nullable();
            $table->string('selected_package_id')->nullable();
            $table->string('selected_package_name')->nullable();
            $table->unsignedSmallInteger('selected_package_hours')->default(0);
            $table->unsignedInteger('selected_package_price')->default(0);
            $table->timestamp('package_reminder_shown_at')->nullable();
            $table->string('origin_cashier_shift_id')->nullable();
            $table->string('origin_staff_id')->nullable();
            $table->string('origin_staff_name')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('tenant_id');
            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('table_involved_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('table_id');
            $table->ulid('staff_id');
            $table->string('staff_name');
            $table->timestamps();
            $table->foreign(['tenant_id', 'table_id'])->references(['tenant_id', 'id'])->on('tables')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'staff_id'])->references(['tenant_id', 'id'])->on('staff')->cascadeOnDelete();
            $table->unique(['tenant_id', 'table_id', 'staff_id']);
        });

        Schema::create('table_layout_positions', function (Blueprint $table) {
            $table->unsignedBigInteger('table_id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->decimal('x_percent', 8, 4)->default(8.0);
            $table->decimal('y_percent', 8, 4)->default(14.0);
            $table->decimal('width_percent', 8, 4)->default(26.0);
            $table->timestamps();
            $table->foreign(['tenant_id', 'table_id'])->references(['tenant_id', 'id'])->on('tables')->cascadeOnDelete();
            $table->index('tenant_id');
            $table->unique(['tenant_id', 'table_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_layout_positions');
        Schema::dropIfExists('table_involved_staff');
        Schema::dropIfExists('tables');
    }
};
