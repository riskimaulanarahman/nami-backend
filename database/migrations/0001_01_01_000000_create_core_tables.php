<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('plan')->default('free');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('staff', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('username');
            $table->string('pin');
            $table->string('role')->default('kasir');
            $table->string('avatar', 10)->default('');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'username']);
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->ulidMorphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name')->default('Rumah Billiard & Cafe');
            $table->text('address')->nullable();
            $table->string('phone', 30)->default('');
            $table->unsignedSmallInteger('tax_percent')->default(0);
            $table->string('paper_size', 10)->default('58mm');
            $table->text('footer_message')->nullable();
            $table->boolean('receipt_show_tax_line')->default(true);
            $table->boolean('receipt_show_cashier')->default(true);
            $table->boolean('receipt_show_payment_info')->default(true);
            $table->boolean('receipt_show_member_info')->default(true);
            $table->boolean('receipt_show_print_time')->default(true);
            $table->timestamps();
            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('business_settings');
        Schema::dropIfExists('staff');
        Schema::dropIfExists('tenants');
    }
};
