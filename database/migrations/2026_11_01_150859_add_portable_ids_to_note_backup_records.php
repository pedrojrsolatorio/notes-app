<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['folders', 'notes', 'note_attachments'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->uuid('portable_id')->nullable();
            });
        }

        foreach (['folders', 'notes', 'note_attachments'] as $tableName) {
            DB::table($tableName)
                ->select('id')
                ->whereNull('portable_id')
                ->chunkById(500, function ($records) use ($tableName): void {
                    foreach ($records as $record) {
                        DB::table($tableName)
                            ->where('id', $record->id)
                            ->update(['portable_id' => (string) Str::uuid()]);
                    }
                });

            Schema::table($tableName, function (Blueprint $table): void {
                $table->uuid('portable_id')->nullable(false)->change();
                $table->unique(['user_id', 'portable_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['folders', 'notes', 'note_attachments'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropUnique(['user_id', 'portable_id']);
                $table->dropColumn('portable_id');
            });
        }
    }
};
