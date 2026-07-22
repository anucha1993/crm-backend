<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Link a payment to a shared slip (gallery). One slip can back many payments
            // across multiple orders; each payment records the split amount in `amount`.
            $table->foreignId('slip_id')->nullable()->after('slip_image')->constrained('slips')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['slip_id']);
            $table->dropColumn('slip_id');
        });
    }
};
