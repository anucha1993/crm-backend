<?php

namespace App\Console\Commands;

use App\Models\Delivery;
use App\Models\VehicleType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecalculateDeliveryWeights extends Command
{
    protected $signature = 'deliveries:recalc-weights {--dry-run : Show changes without saving}';

    protected $description = 'Recalculate delivery_items.weight and deliveries.total_weight using formula: qty × length × product.weight';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $deliveries = Delivery::with(['items.product'])->get();

        $this->info("Processing {$deliveries->count()} deliveries..." . ($dryRun ? ' [DRY RUN]' : ''));

        $changedItems = 0;
        $changedDeliveries = 0;

        $process = function () use ($deliveries, $dryRun, &$changedItems, &$changedDeliveries) {
            foreach ($deliveries as $delivery) {
                $totalWeight = 0;

                foreach ($delivery->items as $item) {
                    $weightPerMeter = (float) ($item->product?->weight ?? 0);
                    $length = (float) ($item->length ?? $item->product?->length ?? 0);
                    $newWeight = $weightPerMeter * $length * (float) $item->quantity;

                    if (abs((float) $item->weight - $newWeight) > 0.0001) {
                        $changedItems++;
                        if (! $dryRun) {
                            $item->update(['weight' => $newWeight]);
                        }
                    }

                    $totalWeight += $newWeight;
                }

                $suggestedVehicle = $delivery->suggested_vehicle;
                if ($totalWeight > 0) {
                    $weightInTons = $totalWeight / 1000;
                    $vehicle = VehicleType::where('is_active', true)
                        ->where('max_weight', '>=', $weightInTons)
                        ->orderBy('max_weight')
                        ->first();
                    if (! $vehicle) {
                        $vehicle = VehicleType::where('is_active', true)
                            ->orderByDesc('max_weight')
                            ->first();
                    }
                    $suggestedVehicle = $vehicle?->name;
                }

                if (abs((float) $delivery->total_weight - $totalWeight) > 0.0001) {
                    $changedDeliveries++;
                    $this->line(sprintf(
                        '  %s: %.2f → %.2f kg',
                        $delivery->delivery_number,
                        (float) $delivery->total_weight,
                        $totalWeight
                    ));
                    if (! $dryRun) {
                        $delivery->update([
                            'total_weight' => $totalWeight,
                            'suggested_vehicle' => $suggestedVehicle,
                        ]);
                    }
                }
            }
        };

        if ($dryRun) {
            $process();
        } else {
            DB::transaction($process);
        }

        $this->info("Updated {$changedItems} items, {$changedDeliveries} deliveries." . ($dryRun ? ' [DRY RUN - no changes saved]' : ''));

        return self::SUCCESS;
    }
}
