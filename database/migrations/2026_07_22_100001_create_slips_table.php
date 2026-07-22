<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slips', function (Blueprint $table) {
            $table->id();
            $table->string('account_type')->nullable()->index();
            $table->string('slip_ref')->nullable();           // transRef from slip2go
            $table->string('slip_image');                     // stored path (public disk)
            $table->boolean('slip_verified')->default(false);
            $table->string('slip_status_code')->nullable();
            $table->json('slip_data')->nullable();
            $table->decimal('amount', 14, 2)->default(0);     // total value of the slip (โอนจริง)

            $table->string('sender_name')->nullable();
            $table->string('sender_bank')->nullable();
            $table->string('sender_account')->nullable();
            $table->string('receiver_name')->nullable();
            $table->string('receiver_bank')->nullable();
            $table->string('receiver_account')->nullable();
            $table->timestamp('transfer_date')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Prevent the same slip (by ref) being registered twice within an account.
            // Null slip_ref (manual slips) are allowed to repeat.
            $table->unique(['account_type', 'slip_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slips');
    }
};
