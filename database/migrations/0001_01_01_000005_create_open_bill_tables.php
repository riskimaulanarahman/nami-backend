<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('open_bills', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code');
            $table->string('customer_name')->default('');
            $table->ulid('member_id')->nullable();
            $table->unsignedInteger('points_to_redeem')->default(0);
            $table->string('status')->default('open');
            $table->ulid('waiting_list_entry_id')->nullable();
            $table->string('origin_cashier_shift_id')->nullable();
            $table->string('origin_staff_id')->nullable();
            $table->string('origin_staff_name')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'member_id'])->references(['tenant_id', 'id'])->on('members')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'waiting_list_entry_id'])->references(['tenant_id', 'id'])->on('waiting_list_entries')->cascadeOnDelete();
            $table->index('tenant_id');
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('open_bill_involved_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('open_bill_id');
            $table->ulid('staff_id');
            $table->string('staff_name');
            $table->timestamps();
            $table->foreign(['tenant_id', 'open_bill_id'])->references(['tenant_id', 'id'])->on('open_bills')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'staff_id'])->references(['tenant_id', 'id'])->on('staff')->cascadeOnDelete();
            $table->unique(['tenant_id', 'open_bill_id', 'staff_id']);
        });

        Schema::create('open_bill_groups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('open_bill_id');
            $table->string('fulfillment_type');
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('table_name')->nullable();
            $table->unsignedInteger('subtotal')->default(0);
            $table->timestamps();
            $table->foreign(['tenant_id', 'open_bill_id'])->references(['tenant_id', 'id'])->on('open_bills')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'table_id'])->references(['tenant_id', 'id'])->on('tables')->cascadeOnDelete();
            $table->index('tenant_id');
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('open_bill_group_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('open_bill_group_id');
            $table->ulid('menu_item_id');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('unit_price')->default(0);
            $table->timestamp('added_at');
            $table->timestamps();
            $table->foreign(['tenant_id', 'open_bill_group_id'])->references(['tenant_id', 'id'])->on('open_bill_groups')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'menu_item_id'])->references(['tenant_id', 'id'])->on('menu_items')->cascadeOnDelete();
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('open_bill_group_items');
        Schema::dropIfExists('open_bill_groups');
        Schema::dropIfExists('open_bill_involved_staff');
        Schema::dropIfExists('open_bills');
    }
};
