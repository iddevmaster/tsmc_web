<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_submissions', function (Blueprint $table) {
            $table->foreignId('parent_submission_id')
                ->nullable()
                ->after('form_id')
                ->constrained('form_submissions')
                ->nullOnDelete();
            $table->unique(['parent_submission_id', 'form_id']);
        });
    }

    public function down(): void
    {
        Schema::table('form_submissions', function (Blueprint $table) {
            $table->dropUnique(['parent_submission_id', 'form_id']);
            $table->dropConstrainedForeignId('parent_submission_id');
        });
    }
};
