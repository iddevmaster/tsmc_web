<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_report_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained('forms')->cascadeOnDelete();
            $table->string('item_code', 64);
            $table->foreignId('condition_field_id')->nullable()->constrained('form_fields')->nullOnDelete();
            $table->json('condition_values')->nullable();
            $table->enum('distinct_by', ['user', 'vehicle', 'field', 'none'])->default('none');
            $table->foreignId('distinct_field_id')->nullable()->constrained('form_fields')->nullOnDelete();
            $table->timestamps();

            $table->unique(['form_id', 'item_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_report_rules');
    }
};
