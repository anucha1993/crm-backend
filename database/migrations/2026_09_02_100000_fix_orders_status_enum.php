<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Widen the ENUM first so legacy values + the new one all fit
        DB::statement("ALTER TABLE orders MODIFY status ENUM('pending', 'confirmed', 'processing', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'pending'");

        // Migrate legacy rows to the values used by the current codebase
        DB::update("UPDATE orders SET status = 'in_progress' WHERE status IN ('confirmed', 'processing')");

        // Shrink ENUM to the canonical set
        DB::statement("ALTER TABLE orders MODIFY status ENUM('pending', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE orders MODIFY status ENUM('pending', 'confirmed', 'processing', 'completed', 'cancelled') NOT NULL DEFAULT 'pending'");
    }
};
