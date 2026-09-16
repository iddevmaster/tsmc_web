<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_field_chain_maps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chain_link_id')->constrained('form_chain_links')->cascadeOnDelete();
            $table->foreignId('target_field_id')->constrained('form_fields')->cascadeOnDelete();
            $table->foreignId('source_field_id')->constrained('form_fields')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['chain_link_id', 'target_field_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_field_chain_maps');
    }
};
