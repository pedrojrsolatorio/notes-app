<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $collation = $this->exactCollation();
        $this->abortOnNameKeyCollisions();

        Schema::table('folders', function (Blueprint $table) use ($collation): void {
            $table->string('name_key', 510)
                ->nullable()
                ->collation($collation);
        });

        DB::table('folders')
            ->select(['id', 'name'])
            ->orderBy('id')
            ->chunkById(500, function ($folders): void {
                foreach ($folders as $folder) {
                    DB::table('folders')
                        ->where('id', $folder->id)
                        ->update(['name_key' => $this->nameKeyFor($folder->name)]);
                }
            });

        Schema::table('folders', function (Blueprint $table) use ($collation): void {
            $table->string('name_key', 510)
                ->nullable(false)
                ->collation($collation)
                ->change();
        });

        Schema::table('folders', function (Blueprint $table): void {
            $table->unique(['user_id', 'name_key'], 'folders_user_id_name_key_unique');
            $table->dropUnique('folders_user_id_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table): void {
            $table->unique(['user_id', 'name'], 'folders_user_id_name_unique');
        });

        Schema::table('folders', function (Blueprint $table): void {
            $table->dropUnique('folders_user_id_name_key_unique');
        });

        Schema::table('folders', function (Blueprint $table): void {
            $table->dropColumn('name_key');
        });
    }

    private function abortOnNameKeyCollisions(): void
    {
        $foldersByNameKey = [];

        foreach (DB::table('folders')->select(['id', 'user_id', 'name'])->orderBy('id')->cursor() as $folder) {
            $nameKey = $this->nameKeyFor($folder->name);
            $userId = (int) $folder->user_id;

            $foldersByNameKey[$userId][$nameKey][] = (int) $folder->id;
        }

        $collisions = [];

        foreach ($foldersByNameKey as $userId => $foldersByKey) {
            foreach ($foldersByKey as $folderIds) {
                if (count($folderIds) > 1) {
                    $collisions[] = sprintf('user %s, folders %s', $userId, implode(', ', $folderIds));
                }
            }
        }

        if ($collisions !== []) {
            throw new RuntimeException(
                'Cannot add normalized folder name uniqueness because names collide after trimming and lowercasing. '
                .'No schema or data changes were made. Rename one folder in each conflict and retry: '
                .implode('; ', $collisions).'.'
            );
        }
    }

    private function exactCollation(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => 'BINARY',
            'mysql', 'mariadb' => 'utf8mb4_bin',
            'pgsql' => 'C',
            'sqlsrv' => 'Latin1_General_100_BIN2',
            default => throw new RuntimeException(
                'Cannot add normalized folder name uniqueness because the database driver does not have a configured exact collation.'
            ),
        };
    }

    private function nameKeyFor(string $name): string
    {
        $trimmedName = preg_replace(
            '/\A[\p{Z}\x{0009}-\x{000D}\x{0085}]+|[\p{Z}\x{0009}-\x{000D}\x{0085}]+\z/u',
            '',
            $name
        );

        if ($trimmedName === null) {
            throw new RuntimeException('A folder name could not be normalized during migration.');
        }

        return mb_strtolower($trimmedName, 'UTF-8');
    }
};
