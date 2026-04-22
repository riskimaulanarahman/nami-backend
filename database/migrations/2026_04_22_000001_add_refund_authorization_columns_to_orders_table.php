<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('refund_authorization_method')->nullable()->after('refund_reason');
            $table->string('refund_authorized_by')->nullable()->after('refund_authorization_method');
            $table->string('refund_authorized_role')->nullable()->after('refund_authorized_by');
            $table->string('refund_owner_email')->nullable()->after('refund_authorized_role');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'refund_authorization_method',
                'refund_authorized_by',
                'refund_authorized_role',
                'refund_owner_email',
            ]);
        });
    }
};
