<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cancelled invoices release their number (invoice_number → NULL) so it can be
 * reused. The original number is kept in cancelled_invoice_number for audit /
 * PDF display. The unique index stays — MySQL allows multiple NULLs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('invoice_number')->nullable()->change();
            $table->string('cancelled_invoice_number')->nullable()->after('invoice_number');
        });

        DB::table('invoices')
            ->where('status', 'cancelled')
            ->whereNotNull('invoice_number')
            ->update([
                'cancelled_invoice_number' => DB::raw('invoice_number'),
                'invoice_number' => null,
            ]);
    }

    public function down(): void
    {
        // Restore numbers only where nothing else has taken them since.
        // Anything else gets a unique placeholder so the NOT NULL change succeeds.
        $taken = DB::table('invoices')->whereNotNull('invoice_number')->pluck('invoice_number')->flip();
        DB::table('invoices')->whereNull('invoice_number')->orderBy('id')->get(['id', 'cancelled_invoice_number'])
            ->each(function ($row) use (&$taken) {
                $number = $row->cancelled_invoice_number;
                if (!$number || isset($taken[$number])) {
                    $number = 'CANCELLED-' . $row->id;
                }
                $taken[$number] = true;
                DB::table('invoices')->where('id', $row->id)->update(['invoice_number' => $number]);
            });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('cancelled_invoice_number');
            $table->string('invoice_number')->nullable(false)->change();
        });
    }
};
