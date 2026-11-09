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
        Schema::table('folders', function (Blueprint $table): void {
            $table->unsignedBigInteger('version')->default(1);
        });

        Schema::create('sync_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 10);
            $table->uuid('entity_portable_id')->index();
            $table->string('action', 10);
            $table->uuid('client_change_id')->nullable();
            $table->uuid('conflict_note_portable_id')->nullable();
            $table->string('status', 10)->nullable();
            $table->string('error')->nullable();
            $table->timestamp('changed_at')->useCurrent();

            $table->index(['user_id', 'id']);
            $table->unique(['user_id', 'client_change_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_changes');

        Schema::table('folders', function (Blueprint $table): void {
            $table->dropColumn('version');
        });
    }
};
