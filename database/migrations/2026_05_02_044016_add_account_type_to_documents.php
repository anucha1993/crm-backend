<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tables = ['quotations', 'orders', 'invoices', 'payments', 'deliveries'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'account_type')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->enum('account_type', ['cash', 'tax'])->default('cash')->after('id');
                    $t->index('account_type');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = ['quotations', 'orders', 'invoices', 'payments', 'deliveries'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'account_type')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->dropIndex($table . '_account_type_index');
                    $t->dropColumn('account_type');
                });
            }
        }
    }
};
