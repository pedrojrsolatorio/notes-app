<?php

use App\Models\Folder;

test('[AC-3] trims surrounding Unicode whitespace and preserves capitalization', function () {
    expect(Folder::normalizeName("\u{2003}MiXeD Café\u{00A0}\t"))->toBe('MiXeD Café');
});

test('[AC-3] derives the lowercase key from the trimmed Unicode name', function () {
    expect(Folder::nameKeyFor("\u{2003}Café\u{00A0}"))->toBe('café');
});

test('[AC-3] rejects malformed UTF-8 folder names', function () {
    expect(fn () => Folder::normalizeName("\xC3\x28"))
        ->toThrow(InvalidArgumentException::class, 'The folder name must be valid UTF-8.');
});
