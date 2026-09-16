<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_chain_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_form_id')->constrained('forms')->cascadeOnDelete();
            $table->foreignId('next_form_id')->constrained('forms')->cascadeOnDelete();
            $table->boolean('copy_selected_user')->default(false);
            $table->boolean('copy_selected_vehicle')->default(false);
            $table->timestamps();

            $table->unique(['source_form_id', 'next_form_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_chain_links');
    }
};
