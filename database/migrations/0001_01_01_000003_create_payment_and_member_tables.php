<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_options', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('cash');
            $table->string('icon', 10)->default('');
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_reference')->default(false);
            $table->string('reference_label')->default('');
            $table->ulid('parent_id')->nullable();
            $table->boolean('is_group')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'parent_id'])->references(['tenant_id', 'id'])->on('payment_options')->cascadeOnDelete();
            $table->index('tenant_id');
        });

        Schema::create('billiard_packages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('duration_hours');
            $table->unsignedInteger('price');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('members', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('phone');
            $table->string('tier')->default('Bronze');
            $table->unsignedInteger('points_balance')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('member_point_ledger', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('member_id');
            $table->ulid('order_id')->nullable();
            $table->string('type');
            $table->integer('points');
            $table->integer('amount')->default(0);
            $table->string('note')->default('');
            $table->timestamps();
            $table->foreign(['tenant_id', 'member_id'])->references(['tenant_id', 'id'])->on('members')->cascadeOnDelete();
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_point_ledger');
        Schema::dropIfExists('members');
        Schema::dropIfExists('billiard_packages');
        Schema::dropIfExists('payment_options');
    }
};
