<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('table_name');
            $table->string('table_type')->default('standard');
            $table->string('session_type');
            $table->string('bill_type');
            $table->string('billiard_billing_mode')->nullable();
            $table->string('dining_type')->nullable();
            $table->timestamp('start_time');
            $table->timestamp('end_time');
            $table->unsignedInteger('duration_minutes')->default(0);
            $table->unsignedSmallInteger('session_duration_hours')->default(0);
            $table->unsignedInteger('rental_cost')->default(0);
            $table->string('selected_package_id')->nullable();
            $table->string('selected_package_name')->nullable();
            $table->unsignedSmallInteger('selected_package_hours')->default(0);
            $table->unsignedInteger('selected_package_price')->default(0);
            $table->unsignedInteger('order_total')->default(0);
            $table->unsignedInteger('grand_total')->default(0);
            $table->unsignedInteger('order_cost')->default(0);
            $table->string('served_by')->default('');
            $table->string('status')->default('completed');
            $table->timestamp('refunded_at')->nullable();
            $table->string('refunded_by')->nullable();
            $table->text('refund_reason')->nullable();
            $table->ulid('payment_method_id')->nullable();
            $table->string('payment_method_name')->nullable();
            $table->string('payment_method_type')->default('cash');
            $table->string('payment_reference')->nullable();
            $table->ulid('cashier_shift_id')->nullable();
            $table->string('refunded_in_cashier_shift_id')->nullable();
            $table->string('origin_cashier_shift_id')->nullable();
            $table->string('origin_staff_id')->nullable();
            $table->string('origin_staff_name')->nullable();
            $table->boolean('is_continued_from_previous_shift')->default(false);
            $table->ulid('member_id')->nullable();
            $table->string('member_code')->nullable();
            $table->string('member_name')->nullable();
            $table->unsignedInteger('points_earned')->default(0);
            $table->unsignedInteger('points_redeemed')->default(0);
            $table->unsignedInteger('redeem_amount')->default(0);
            $table->timestamps();
            $table->foreign(['tenant_id', 'table_id'])->references(['tenant_id', 'id'])->on('tables')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'cashier_shift_id'])->references(['tenant_id', 'id'])->on('cashier_shifts')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'member_id'])->references(['tenant_id', 'id'])->on('members')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'payment_method_id'])->references(['tenant_id', 'id'])->on('payment_options')->cascadeOnDelete();
            $table->index('tenant_id');
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('order_involved_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('order_id');
            $table->ulid('staff_id');
            $table->string('staff_name');
            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('orders')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'staff_id'])->references(['tenant_id', 'id'])->on('staff')->cascadeOnDelete();
            $table->unique(['tenant_id', 'order_id', 'staff_id']);
        });

        Schema::create('order_groups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('order_id');
            $table->string('fulfillment_type');
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('table_name')->nullable();
            $table->unsignedInteger('subtotal')->default(0);
            $table->timestamps();
            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('orders')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'table_id'])->references(['tenant_id', 'id'])->on('tables')->cascadeOnDelete();
            $table->index('tenant_id');
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('order_group_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('order_group_id');
            $table->ulid('menu_item_id');
            $table->string('menu_item_name');
            $table->string('menu_item_emoji', 10)->default('');
            $table->unsignedInteger('unit_price')->default(0);
            $table->unsignedInteger('unit_cost')->default(0);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('subtotal')->default(0);
            $table->foreign(['tenant_id', 'order_group_id'])->references(['tenant_id', 'id'])->on('order_groups')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'menu_item_id'])->references(['tenant_id', 'id'])->on('menu_items')->cascadeOnDelete();
            $table->index('tenant_id');
        });

        Schema::table('member_point_ledger', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('member_point_ledger', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'order_id']);
        });

        Schema::dropIfExists('order_group_items');
        Schema::dropIfExists('order_groups');
        Schema::dropIfExists('order_involved_staff');
        Schema::dropIfExists('orders');
    }
};
