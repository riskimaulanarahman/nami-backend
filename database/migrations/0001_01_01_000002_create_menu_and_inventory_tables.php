<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('emoji', 10)->default('');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('menu_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('legacy_category')->default('food');
            $table->ulid('category_id');
            $table->unsignedInteger('price')->default(0);
            $table->unsignedInteger('cost')->default(0);
            $table->string('emoji', 10)->default('');
            $table->text('description')->nullable();
            $table->boolean('is_available')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->foreign(['tenant_id', 'category_id'])->references(['tenant_id', 'id'])->on('menu_categories')->cascadeOnDelete();
            $table->index('tenant_id');
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('ingredients', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('unit')->default('pcs');
            $table->decimal('stock', 12, 4)->default(0);
            $table->decimal('min_stock', 12, 4)->default(0);
            $table->unsignedInteger('unit_cost')->default(0);
            $table->timestamp('last_restocked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('menu_item_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('menu_item_id');
            $table->ulid('ingredient_id');
            $table->decimal('quantity', 12, 4)->default(1);
            $table->foreign(['tenant_id', 'menu_item_id'])->references(['tenant_id', 'id'])->on('menu_items')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'ingredient_id'])->references(['tenant_id', 'id'])->on('ingredients')->cascadeOnDelete();
            $table->unique(['tenant_id', 'menu_item_id', 'ingredient_id']);
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('ingredient_id');
            $table->string('type');
            $table->decimal('quantity', 12, 4);
            $table->string('reason')->default('');
            $table->string('adjusted_by')->default('');
            $table->decimal('previous_stock', 12, 4)->default(0);
            $table->decimal('new_stock', 12, 4)->default(0);
            $table->timestamps();
            $table->foreign(['tenant_id', 'ingredient_id'])->references(['tenant_id', 'id'])->on('ingredients')->cascadeOnDelete();
            $table->index('tenant_id');
        });

        Schema::create('table_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('table_id');
            $table->ulid('menu_item_id');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('unit_price')->default(0);
            $table->timestamp('added_at');
            $table->timestamps();
            $table->foreign(['tenant_id', 'table_id'])->references(['tenant_id', 'id'])->on('tables')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'menu_item_id'])->references(['tenant_id', 'id'])->on('menu_items')->cascadeOnDelete();
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_order_items');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('menu_item_recipes');
        Schema::dropIfExists('ingredients');
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('menu_categories');
    }
};
