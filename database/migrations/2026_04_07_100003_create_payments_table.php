<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->enum('method', ['cash', 'transfer', 'pocket_money'])->default('transfer');
            $table->decimal('amount', 14, 2);
            $table->boolean('is_deposit')->default(false); // มัดจำ
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('notes')->nullable();
            $table->text('reject_reason')->nullable();

            // Slip verification
            $table->string('slip_image')->nullable();
            $table->boolean('slip_verified')->default(false);
            $table->string('slip_ref')->nullable(); // transRef from slip2go
            $table->json('slip_data')->nullable(); // full slip2go response
            $table->string('slip_status_code')->nullable(); // slip2go code e.g. 200000

            // Transfer details (from slip or manual)
            $table->string('sender_name')->nullable();
            $table->string('sender_bank')->nullable();
            $table->string('sender_account')->nullable();
            $table->string('receiver_name')->nullable();
            $table->string('receiver_bank')->nullable();
            $table->string('receiver_account')->nullable();
            $table->decimal('transfer_amount', 14, 2)->nullable();
            $table->timestamp('transfer_date')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payment_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('action'); // created, approved, rejected, resubmitted, slip_verified
            $table->text('summary')->nullable();
            $table->json('details')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_logs');
        Schema::dropIfExists('payments');
    }
};
