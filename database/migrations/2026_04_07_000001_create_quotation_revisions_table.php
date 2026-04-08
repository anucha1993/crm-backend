<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add revision_number to quotations table
        Schema::table('quotations', function (Blueprint $table) {
            $table->unsignedInteger('revision_number')->default(0)->after('status');
        });

        // Create quotation_revisions table for timeline/log
        Schema::create('quotation_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('action'); // created, updated, status_changed, duplicated
            $table->text('summary')->nullable(); // description of changes
            $table->json('changes')->nullable(); // JSON diff of changed fields
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_revisions');

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn('revision_number');
        });
    }
};
