<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->string('title_key', 510)->nullable();
            $table->index(['user_id', 'updated_at', 'id'], 'notes_user_updated_id_index');
            $table->index(['user_id', 'folder_id', 'updated_at', 'id'], 'notes_user_folder_updated_id_index');
            $table->index(['user_id', 'deleted_at', 'updated_at', 'id'], 'notes_user_deleted_updated_id_index');
            $table->index(['user_id', 'title_key', 'id'], 'notes_user_title_key_id_index');
        });

        DB::table('notes')
            ->select(['id', 'title'])
            ->orderBy('id')
            ->chunkById(500, function ($notes): void {
                foreach ($notes as $note) {
                    DB::table('notes')
                        ->where('id', $note->id)
                        ->update(['title_key' => $this->titleKeyFor($note->title)]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->dropIndex('notes_user_title_key_id_index');
            $table->dropIndex('notes_user_deleted_updated_id_index');
            $table->dropIndex('notes_user_folder_updated_id_index');
            $table->dropIndex('notes_user_updated_id_index');
            $table->dropColumn('title_key');
        });
    }

    private function titleKeyFor(string $title): string
    {
        $normalizedTitle = preg_replace(
            '/\A[\p{Z}\x{0009}-\x{000D}\x{0085}]+|[\p{Z}\x{0009}-\x{000D}\x{0085}]+\z/u',
            '',
            $title
        );

        if ($normalizedTitle === null) {
            throw new RuntimeException('A note title could not be normalized during migration.');
        }

        return mb_strtolower($normalizedTitle, 'UTF-8');
    }
};
