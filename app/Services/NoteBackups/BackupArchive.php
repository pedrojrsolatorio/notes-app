<?php

namespace App\Services\NoteBackups;

use ZipArchive;

class BackupArchive
{
    public function __construct(private StrictJsonDecoder $jsonDecoder) {}

    public function createEncryptedArchive(string $innerArchivePath, string $outputPath, string $password): void
    {
        $innerSize = filesize($innerArchivePath);

        if ($innerSize === false || $innerSize > config('import_export.max_expanded_bytes')) {
            throw new NoteBackupException('The backup exceeds the supported size limit.', 413, 'size_exceeded');
        }

        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            $password,
            $salt,
            3,
            67108864,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
        $payloadPath = dirname($outputPath).'/payload-'.bin2hex(random_bytes(8)).'.tmp';
        $state = null;
        $input = null;
        $output = null;

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            $input = fopen($innerArchivePath, 'rb');
            $output = fopen($payloadPath, 'wb');

            if ($input === false || $output === false) {
                throw new NoteBackupException('The backup could not be prepared.', 500, 'storage_error');
            }

            $this->writeAll($output, $header);
            $remaining = $innerSize;

            while ($remaining > 0) {
                $chunk = fread($input, max(1, min((int) config('import_export.chunk_bytes'), $remaining)));

                if ($chunk === false || $chunk === '') {
                    throw new NoteBackupException('The backup could not be read completely.', 500, 'storage_error');
                }

                $remaining -= strlen($chunk);
                $tag = $remaining === 0
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
                $this->writeAll($output, pack('N', strlen($ciphertext)).$ciphertext);

                if (ftell($output) > config('import_export.max_archive_bytes')) {
                    throw new NoteBackupException('The backup exceeds the supported size limit.', 413, 'size_exceeded');
                }
            }

            fclose($input);
            fclose($output);
            $input = null;
            $output = null;

            $outer = new ZipArchive;

            if ($outer->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new NoteBackupException('The backup could not be written.', 500, 'storage_error');
            }

            $format = json_encode([
                'format_version' => 1,
                'kdf' => 'argon2id13',
                'salt' => base64_encode($salt),
                'opslimit' => 3,
                'memlimit' => 67108864,
                'cipher' => 'secretstream_xchacha20poly1305',
            ], JSON_THROW_ON_ERROR);

            if (! $outer->addFromString('format.json', $format) || ! $outer->addFile($payloadPath, 'payload.bin')) {
                $outer->close();
                throw new NoteBackupException('The backup could not be written.', 500, 'storage_error');
            }

            if (! $outer->setCompressionName('payload.bin', ZipArchive::CM_STORE)) {
                $outer->close();
                throw new NoteBackupException('The backup could not be written.', 500, 'storage_error');
            }

            $closed = $outer->close();

            if (! $closed) {
                throw new NoteBackupException('The backup could not be finalized.', 500, 'storage_error');
            }

            $archiveSize = filesize($outputPath);

            if ($archiveSize === false || $archiveSize > config('import_export.max_archive_bytes')) {
                throw new NoteBackupException('The backup exceeds the supported size limit.', 413, 'size_exceeded');
            }
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }

            if (is_resource($output)) {
                fclose($output);
            }

            if (is_file($payloadPath) && ! unlink($payloadPath)) {
                report(new \RuntimeException('A temporary encrypted backup payload could not be removed.'));
            }

            if (is_string($state)) {
                sodium_memzero($state);
            }

            sodium_memzero($key);
        }
    }

    public function decryptOuterArchive(string $archivePath, string $password, string $workspace): string
    {
        $archiveSize = filesize($archivePath);

        if ($archiveSize === false || $archiveSize > config('import_export.max_archive_bytes')) {
            throw new NoteBackupException('The backup exceeds the supported size limit.', 413, 'size_exceeded');
        }

        $outer = new ZipArchive;

        if ($outer->open($archivePath) !== true) {
            throw new NoteBackupException('The uploaded file is not a valid backup archive.', 400, 'invalid_archive');
        }

        $payload = null;
        $state = null;
        $key = null;
        $output = null;

        try {
            if ($outer->numFiles !== 2) {
                throw new NoteBackupException('The backup archive has unexpected entries.', 400, 'unexpected_entries');
            }

            $names = [];

            for ($index = 0; $index < $outer->numFiles; $index++) {
                $stat = $outer->statIndex($index);

                if ($stat === false || $this->isSymlink($outer, $index)) {
                    throw new NoteBackupException('The backup archive contains an unsafe entry.', 400, 'unsafe_entry');
                }

                $name = $stat['name'];

                if (isset($names[$name])) {
                    throw new NoteBackupException('The backup archive contains duplicate entries.', 400, 'duplicate_entry');
                }

                $names[$name] = $index;
            }

            if (array_keys($names) !== ['format.json', 'payload.bin'] && array_keys($names) !== ['payload.bin', 'format.json']) {
                throw new NoteBackupException('The backup archive has unexpected entries.', 400, 'unexpected_entries');
            }

            $formatJson = $this->readZipEntry($outer, 'format.json', (int) config('import_export.max_format_bytes'));
            $format = $this->jsonDecoder->decode($formatJson);
            $this->validateFormat($format);

            $salt = base64_decode($format['salt'], true);

            if ($salt === false || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES || base64_encode($salt) !== $format['salt']) {
                throw new NoteBackupException('The backup format description is invalid.', 400, 'invalid_format');
            }

            $payloadStat = $outer->statName('payload.bin');

            if ($payloadStat === false || $payloadStat['size'] > config('import_export.max_archive_bytes')) {
                throw new NoteBackupException('The backup exceeds the supported size limit.', 413, 'size_exceeded');
            }

            $key = sodium_crypto_pwhash(
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
                $password,
                $salt,
                3,
                67108864,
                SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
            );
            $payload = $outer->getStream('payload.bin');
            $outputPath = $workspace.'/inner.zip';
            $output = fopen($outputPath, 'wb');

            if ($payload === false || $output === false) {
                throw new NoteBackupException('The backup payload could not be read.', 400, 'invalid_archive');
            }

            $header = $this->readBytes($payload, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $totalBytes = 0;
            $finalTagSeen = false;

            while (true) {
                $lengthBytes = $this->readBytes($payload, 4, allowEof: true);

                if ($lengthBytes === null) {
                    break;
                }

                $unpackedLength = unpack('Nlength', $lengthBytes);

                if ($unpackedLength === false) {
                    throw $this->authenticationFailure();
                }

                $length = $unpackedLength['length'];

                if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES
                    || $length > (int) config('import_export.chunk_bytes') + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                    throw $this->authenticationFailure();
                }

                $ciphertext = $this->readBytes($payload, $length);
                $message = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $ciphertext);

                if ($message === false) {
                    throw $this->authenticationFailure();
                }

                [$plaintext, $tag] = $message;

                if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
                    && $tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    throw $this->authenticationFailure();
                }

                $totalBytes += strlen($plaintext);

                if ($totalBytes > config('import_export.max_expanded_bytes')) {
                    throw new NoteBackupException('The backup exceeds the supported expanded size limit.', 413, 'size_exceeded');
                }

                $this->writeAll($output, $plaintext);
                $finalTagSeen = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;

                if ($finalTagSeen) {
                    if ($this->readBytes($payload, 1, allowEof: true) !== null) {
                        throw $this->authenticationFailure();
                    }

                    break;
                }
            }

            if (! $finalTagSeen) {
                throw $this->authenticationFailure();
            }

            fclose($output);
            $output = null;

            return $outputPath;
        } finally {
            if (is_resource($payload)) {
                fclose($payload);
            }

            if (is_resource($output)) {
                fclose($output);
            }

            if (is_string($state)) {
                sodium_memzero($state);
            }

            if (is_string($key)) {
                sodium_memzero($key);
            }

            $outer->close();
        }
    }

    /**
     * @return array{manifest: array<string, mixed>, attachment_paths: array<string, string>}
     */
    public function extractInnerArchive(string $innerArchivePath, string $workspace): array
    {
        $inner = new ZipArchive;

        if ($inner->open($innerArchivePath) !== true) {
            throw new NoteBackupException('The decrypted backup payload is invalid.', 400, 'invalid_payload');
        }

        $manifestJson = null;
        $attachmentPaths = [];
        $seenNames = [];
        $totalBytes = 0;

        try {
            if ($inner->numFiles < 1 || $inner->numFiles > config('import_export.max_records') + 1) {
                throw new NoteBackupException('The backup contains too many entries.', 413, 'size_exceeded');
            }

            for ($index = 0; $index < $inner->numFiles; $index++) {
                $stat = $inner->statIndex($index);

                if ($stat === false || $this->isSymlink($inner, $index)) {
                    throw new NoteBackupException('The backup payload contains an unsafe entry.', 400, 'unsafe_entry');
                }

                $name = $stat['name'];

                if (isset($seenNames[$name])) {
                    throw new NoteBackupException('The backup payload contains duplicate entries.', 400, 'duplicate_entry');
                }

                $seenNames[$name] = true;

                if ($name !== 'manifest.json' && ! preg_match('/\Aattachments\/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $name)) {
                    throw new NoteBackupException('The backup payload contains an unsafe entry.', 400, 'unsafe_entry');
                }

                $declaredSize = $stat['size'];

                if ($declaredSize < 0 || $declaredSize > config('import_export.max_expanded_bytes')) {
                    throw new NoteBackupException('The backup exceeds the supported expanded size limit.', 413, 'size_exceeded');
                }

                if ($name === 'manifest.json' && $declaredSize > config('import_export.max_manifest_bytes')) {
                    throw new NoteBackupException('The backup manifest exceeds the supported size limit.', 413, 'size_exceeded');
                }

                if ($name !== 'manifest.json' && $declaredSize > config('import_export.max_attachment_bytes')) {
                    throw new NoteBackupException('An attachment exceeds the supported size limit.', 413, 'size_exceeded');
                }

                $entry = $inner->getStream($name);

                if ($entry === false) {
                    throw new NoteBackupException('A backup entry could not be read.', 400, 'unreadable_entry');
                }

                $targetPath = $name === 'manifest.json'
                    ? null
                    : $workspace.'/attachment-'.basename($name);
                $target = $targetPath === null ? null : fopen($targetPath, 'wb');

                if ($targetPath !== null && $target === false) {
                    fclose($entry);
                    throw new NoteBackupException('A backup attachment could not be staged.', 500, 'storage_error');
                }

                $entryBytes = 0;
                $manifestContents = '';

                try {
                    while (! feof($entry)) {
                        $chunk = fread($entry, max(1, (int) config('import_export.chunk_bytes')));

                        if ($chunk === false) {
                            throw new NoteBackupException('A backup entry could not be read.', 400, 'unreadable_entry');
                        }

                        if ($chunk === '') {
                            if (! feof($entry)) {
                                throw new NoteBackupException('A backup entry could not be read.', 400, 'unreadable_entry');
                            }

                            continue;
                        }

                        $entryBytes += strlen($chunk);
                        $totalBytes += strlen($chunk);

                        if ($totalBytes > config('import_export.max_expanded_bytes')
                            || ($name === 'manifest.json' && $entryBytes > config('import_export.max_manifest_bytes'))
                            || ($name !== 'manifest.json' && $entryBytes > config('import_export.max_attachment_bytes'))) {
                            throw new NoteBackupException('The backup exceeds the supported expanded size limit.', 413, 'size_exceeded');
                        }

                        if ($target === null) {
                            $manifestContents .= $chunk;
                        } else {
                            if (! is_resource($target)) {
                                throw new NoteBackupException('A backup attachment could not be staged.', 500, 'storage_error');
                            }

                            $this->writeAll($target, $chunk);
                        }
                    }
                } finally {
                    fclose($entry);

                    if (is_resource($target)) {
                        fclose($target);
                    }
                }

                if ($entryBytes !== $declaredSize) {
                    throw new NoteBackupException('A backup entry is incomplete or damaged.', 400, 'invalid_payload');
                }

                if ($name === 'manifest.json') {
                    if ($manifestJson !== null) {
                        throw new NoteBackupException('The backup payload contains duplicate manifests.', 400, 'duplicate_entry');
                    }

                    $manifestJson = $manifestContents;
                } else {
                    $attachmentId = strtolower(basename($name));
                    $attachmentPaths[$attachmentId] = $targetPath;
                }
            }
        } finally {
            $inner->close();
        }

        if (! is_string($manifestJson)) {
            throw new NoteBackupException('The backup manifest is missing.', 400, 'missing_manifest');
        }

        $manifest = $this->jsonDecoder->decode($manifestJson);

        if (! is_array($manifest)) {
            throw new NoteBackupException('The backup manifest is invalid.', 400, 'invalid_manifest');
        }

        return [
            'manifest' => $manifest,
            'attachment_paths' => $attachmentPaths,
        ];
    }

    private function validateFormat(mixed $format): void
    {
        if (! is_array($format)) {
            throw new NoteBackupException('The backup format description is invalid.', 400, 'invalid_format');
        }

        $keys = array_keys($format);
        $expectedKeys = ['format_version', 'kdf', 'salt', 'opslimit', 'memlimit', 'cipher'];
        sort($keys);
        sort($expectedKeys);

        if ($keys !== $expectedKeys
            || ($format['format_version'] ?? null) !== 1
            || ($format['kdf'] ?? null) !== 'argon2id13'
            || ($format['opslimit'] ?? null) !== 3
            || ($format['memlimit'] ?? null) !== 67108864
            || ($format['cipher'] ?? null) !== 'secretstream_xchacha20poly1305'
            || ! is_string($format['salt'] ?? null)) {
            throw new NoteBackupException('The backup uses an unsupported format.', 400, 'unsupported_format');
        }
    }

    private function readZipEntry(ZipArchive $archive, string $name, int $maxBytes): string
    {
        $stat = $archive->statName($name);

        if ($stat === false || $stat['size'] > $maxBytes) {
            throw new NoteBackupException('The backup format description is invalid or too large.', 413, 'size_exceeded');
        }

        $stream = $archive->getStream($name);

        if ($stream === false) {
            throw new NoteBackupException('The backup format description could not be read.', 400, 'invalid_format');
        }

        $contents = '';

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, max(1, min(8192, $maxBytes + 1 - strlen($contents))));

                if ($chunk === false) {
                    throw new NoteBackupException('The backup format description could not be read.', 400, 'invalid_format');
                }

                $contents .= $chunk;

                if (strlen($contents) > $maxBytes) {
                    throw new NoteBackupException('The backup format description exceeds the supported size limit.', 413, 'size_exceeded');
                }
            }
        } finally {
            fclose($stream);
        }

        return $contents;
    }

    /**
     * @param  resource  $stream
     */
    private function readBytes(mixed $stream, int $length, bool $allowEof = false): ?string
    {
        $contents = '';

        while (strlen($contents) < $length) {
            $chunk = fread($stream, max(1, $length - strlen($contents)));

            if ($chunk === false) {
                throw $this->authenticationFailure();
            }

            if ($chunk === '') {
                if (feof($stream) && $contents === '' && $allowEof) {
                    return null;
                }

                throw $this->authenticationFailure();
            }

            $contents .= $chunk;
        }

        return $contents;
    }

    private function isSymlink(ZipArchive $archive, int $index): bool
    {
        $system = 0;
        $attributes = 0;

        if (! $archive->getExternalAttributesIndex($index, $system, $attributes)) {
            return false;
        }

        return $system === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000;
    }

    /**
     * @param  resource  $stream
     */
    private function writeAll(mixed $stream, string $contents): void
    {
        $offset = 0;

        while ($offset < strlen($contents)) {
            $written = fwrite($stream, substr($contents, $offset));

            if ($written === false || $written === 0) {
                throw new NoteBackupException('A backup file could not be written.', 500, 'storage_error');
            }

            $offset += $written;
        }
    }

    private function authenticationFailure(): NoteBackupException
    {
        return new NoteBackupException(
            'The password is incorrect or the backup is damaged.',
            422,
            'authentication_failed',
        );
    }
}
