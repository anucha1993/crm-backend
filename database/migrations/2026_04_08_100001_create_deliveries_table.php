<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add delivery_status to orders table
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('delivery_status', ['not_delivered', 'partially_delivered', 'fully_delivered', 'cancelled'])
                ->default('not_delivered')
                ->after('status');
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_number')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_address_id')->nullable()->constrained('customer_addresses')->nullOnDelete();
            $table->enum('status', ['pending', 'delivering', 'delivered', 'cancelled'])->default('pending');
            $table->date('delivery_date');
            $table->timestamp('delivered_at')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('total_weight', 14, 4)->default(0);
            $table->string('suggested_vehicle')->nullable();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('delivery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->string('unit')->default('ชิ้น');
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('thickness', 12, 4)->nullable();
            $table->decimal('length', 12, 4)->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('weight', 12, 4)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_items');
        Schema::dropIfExists('deliveries');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('delivery_status');
        });
    }
};
