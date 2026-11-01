<?php

namespace App\Services\NoteBackups;

use RuntimeException;
use Throwable;

class NoteBackupException extends RuntimeException
{
    /**
     * @param  array<int, array{entity_type: string, display_name: string, reason_code: string, reason: string}>  $details
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $reasonCode = 'invalid_archive',
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
