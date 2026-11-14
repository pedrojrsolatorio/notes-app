<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->dateTime('reminder_at')->nullable();
            $table->string('reminder_status')->nullable();
            $table->uuid('reminder_generation')->nullable();
            $table->index(['reminder_status', 'reminder_at'], 'notes_reminder_due_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->dropIndex('notes_reminder_due_index');
            $table->dropColumn(['reminder_at', 'reminder_status', 'reminder_generation']);
        });
    }
};
