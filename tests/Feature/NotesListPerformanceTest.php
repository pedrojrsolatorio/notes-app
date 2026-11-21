<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('[AC-1, AC-10] keeps the 10000 note list paths below the 500 ms p95 target', function () {
    $user = User::factory()->create();
    $folder = $user->folders()->create(['name' => 'Benchmark folder']);
    $timestamp = '2026-01-01 00:00:00';
    $rows = [];

    for ($index = 0; $index < 10_000; $index++) {
        $trashed = $index >= 80 && $index < 120;
        $searchMatch = $index < 40;
        $inFolder = $index >= 120 && $index < 155;
        $title = $searchMatch ? 'Needle note '.$index : 'Note '.$index;

        $rows[] = [
            'user_id' => $user->id,
            'folder_id' => $inFolder ? $folder->id : null,
            'portable_id' => (string) Str::uuid(),
            'title' => $title,
            'title_key' => mb_strtolower($title, 'UTF-8'),
            'content' => $searchMatch ? '<p>needle body</p>' : '<p>Benchmark body</p>',
            'version' => 1,
            'deleted_at' => $trashed ? $timestamp : null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        if (count($rows) === 200) {
            DB::table('notes')->insert($rows);
            $rows = [];
        }
    }

    if ($rows !== []) {
        DB::table('notes')->insert($rows);
    }

    $this->actingAs($user);

    $anchor = DB::table('notes')->where('id', 51)->firstOrFail();
    $finalPageCursor = rtrim(strtr(base64_encode(json_encode([
        'v' => 1,
        'sort' => 'newest',
        'filter' => ['search' => '', 'folder_id' => null, 'trash' => false],
        'key' => '2026-01-01T00:00:00.000000Z',
        'anchor' => $anchor->portable_id,
    ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

    $measure = function (string $name, Closure $prepare, Closure $read, int $expectedRows): void {
        $read($prepare(), -1);
        $durations = [];
        $lastResult = null;

        for ($iteration = 0; $iteration < 100; $iteration++) {
            $component = $prepare();
            $startedAt = hrtime(true);
            $lastResult = $read($component, $iteration);
            $durations[] = (hrtime(true) - $startedAt) / 1_000_000;
        }

        sort($durations, SORT_NUMERIC);
        $p95 = $durations[(int) ceil(count($durations) * 0.95) - 1];

        fwrite(STDOUT, sprintf("%s p95: %.2f ms\n", $name, $p95));
        $this->assertLessThan(500, $p95, sprintf('%s exceeded the 500 ms p95 target.', $name));
        $this->assertCount($expectedRows, $lastResult->get('noteRows'));
    };

    $measure(
        'first page',
        fn () => null,
        fn () => Livewire::test('notes.index'),
        50,
    );

    $measure(
        'newest Load more',
        fn () => Livewire::test('notes.index'),
        fn ($component) => $component->call('loadMoreNotes'),
        100,
    );

    $measure(
        'search',
        fn () => Livewire::test('notes.index'),
        fn ($component) => $component->set('search', 'needle'),
        40,
    );

    $measure(
        'folder',
        fn () => Livewire::test('notes.index'),
        fn ($component) => $component->call('selectFolder', $folder->id),
        35,
    );

    $measure(
        'Trash',
        fn () => Livewire::test('notes.index'),
        fn ($component) => $component->call('showTrash'),
        40,
    );

    $measure(
        'title A to Z',
        fn () => Livewire::test('notes.index'),
        fn ($component) => $component->set('sortBy', 'title_asc'),
        50,
    );

    $measure(
        'title Z to A',
        fn () => Livewire::test('notes.index'),
        fn ($component) => $component->set('sortBy', 'title_desc'),
        50,
    );

    $measure(
        'newest final page and count',
        fn () => Livewire::test('notes.index')->set('notesCursor', $finalPageCursor),
        fn ($component) => $component->call('loadMoreNotes'),
        100,
    );
})->group('performance');
