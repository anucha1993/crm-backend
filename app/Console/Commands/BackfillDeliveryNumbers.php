<?php

namespace App\Console\Commands;

use App\Models\Delivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillDeliveryNumbers extends Command
{
    protected $signature = 'deliveries:backfill-numbers {--dry-run : Show changes without saving} {--force : Skip confirmation prompt}';

    protected $description = 'Renumber existing deliveries to the DLV-{YYYYMM-NNNN}-{running} format derived from their quotation number';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Load every delivery (all account types), earliest first so it gets -001.
        $deliveries = Delivery::withoutGlobalScope('account')
            ->with('order.quotation')
            ->orderBy('id')
            ->get();

        if ($deliveries->isEmpty()) {
            $this->info('ไม่มีใบส่งของในระบบ');
            return self::SUCCESS;
        }

        $counters = [];
        $plan = [];

        foreach ($deliveries as $delivery) {
            // Same source as runtime: quotation number, fallback to order number.
            $source = $delivery->order?->quotation?->quotation_number
                ?? $delivery->order?->order_number;

            if (! $source) {
                $this->warn("ข้าม #{$delivery->id} ({$delivery->delivery_number}) — ไม่พบใบเสนอราคา/ออเดอร์");
                continue;
            }

            $base = 'DLV-' . Str::after($source, '-');          // e.g. DLV-202607-0006
            $counters[$base] = ($counters[$base] ?? 0) + 1;
            $newNumber = $base . '-' . str_pad($counters[$base], 3, '0', STR_PAD_LEFT);

            if ($newNumber !== $delivery->delivery_number) {
                $plan[] = [
                    'id' => $delivery->id,
                    'old' => $delivery->delivery_number,
                    'new' => $newNumber,
                ];
            }
        }

        if (empty($plan)) {
            $this->info('เลขใบส่งของถูกต้องตามรูปแบบใหม่อยู่แล้ว ไม่มีอะไรต้องเปลี่ยน');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'เลขเดิม', 'เลขใหม่'],
            array_map(fn ($p) => [$p['id'], $p['old'], $p['new']], $plan),
        );
        $this->line('รวม ' . count($plan) . ' รายการที่จะเปลี่ยน' . ($dryRun ? ' [DRY RUN]' : ''));

        if ($dryRun) {
            $this->info('[dry-run] ไม่มีการบันทึกข้อมูล');
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('ยืนยันอัปเดตเลขใบส่งของทั้งหมดนี้?')) {
            $this->warn('ยกเลิก');
            return self::SUCCESS;
        }

        // Two passes to avoid hitting the unique constraint when numbers are swapped
        // between rows. Uses DB::table so timestamps are left untouched.
        DB::transaction(function () use ($plan) {
            foreach ($plan as $p) {
                DB::table('deliveries')->where('id', $p['id'])
                    ->update(['delivery_number' => '__TMP__' . $p['id']]);
            }
            foreach ($plan as $p) {
                DB::table('deliveries')->where('id', $p['id'])
                    ->update(['delivery_number' => $p['new']]);
            }
        });

        $this->info('อัปเดตเลขใบส่งของเสร็จ ' . count($plan) . ' รายการ');
        return self::SUCCESS;
    }
}
