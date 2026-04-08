<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            // รายละเอียดเบื้องต้น
            $table->string('code')->unique();
            $table->string('name');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('unit');                   // ชิ้น, แผ่น, ต้น, ท่อน, แท่ง, ก้อน, ม้วน, กำหนดเอง
            $table->string('unit_custom')->nullable(); // กรณีเลือก กำหนดเอง

            // รายละเอียดขนาด
            $table->string('cross_section')->nullable();      // ขนาดหน้าตัด
            $table->decimal('length', 12, 4)->nullable();     // ความยาว
            $table->string('length_unit')->nullable();         // เมตร, เซนติเมตร, มิลลิเมตร, นิ้ว, กำหนดเอง
            $table->string('length_unit_custom')->nullable();
            $table->decimal('thickness', 12, 4)->nullable();  // ความหนา
            $table->string('thickness_unit')->nullable();
            $table->string('thickness_unit_custom')->nullable();

            // ประเภทเหล็ก
            $table->string('steel_type')->nullable();          // ลวด 4-7 เส้น
            $table->string('side_steel')->default('unspecified'); // unspecified, hide, show
            $table->string('size_type')->default('standard');    // standard, custom
            $table->text('custom_note')->nullable();             // กรณี size_type = custom

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
