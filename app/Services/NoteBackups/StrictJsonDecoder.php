<?php

namespace App\Services\NoteBackups;

use JsonException;

class StrictJsonDecoder
{
    public function decode(string $json): mixed
    {
        $offset = 0;
        $this->skipWhitespace($json, $offset);
        $this->parseValue($json, $offset, 0);
        $this->skipWhitespace($json, $offset);

        if ($offset !== strlen($json)) {
            throw new NoteBackupException('The backup contains invalid JSON.', 400, 'invalid_json');
        }

        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new NoteBackupException('The backup contains invalid JSON.', 400, 'invalid_json', previous: $exception);
        }
    }

    private function parseValue(string $json, int &$offset, int $depth): void
    {
        if ($depth > 512 || $offset >= strlen($json)) {
            throw new NoteBackupException('The backup contains invalid JSON.', 400, 'invalid_json');
        }

        $this->skipWhitespace($json, $offset);
        $character = $json[$offset] ?? '';

        if ($character === '{') {
            $this->parseObject($json, $offset, $depth + 1);

            return;
        }

        if ($character === '[') {
            $this->parseArray($json, $offset, $depth + 1);

            return;
        }

        if ($character === '"') {
            $this->readString($json, $offset);

            return;
        }

        $start = $offset;
        while ($offset < strlen($json) && ! str_contains(",]} \t\r\n", $json[$offset])) {
            $offset++;
        }

        $literal = substr($json, $start, $offset - $start);

        if ($literal === '' || ! preg_match('/\A(?:true|false|null|-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?)\z/', $literal)) {
            throw new NoteBackupException('The backup contains invalid JSON.', 400, 'invalid_json');
        }
    }

    private function parseObject(string $json, int &$offset, int $depth): void
    {
        $offset++;
        $this->skipWhitespace($json, $offset);
        $keys = [];

        if (($json[$offset] ?? '') === '}') {
            $offset++;

            return;
        }

        while (true) {
            if (($json[$offset] ?? '') !== '"') {
                throw new NoteBackupException('The backup contains invalid JSON.', 400, 'invalid_json');
            }

            $key = $this->readString($json, $offset);

            if (array_key_exists($key, $keys)) {
                throw new NoteBackupException('The backup contains duplicate JSON keys.', 400, 'duplicate_json_key');
            }

            $keys[$key] = true;
            $this->skipWhitespace($json, $offset);

            if (($json[$offset] ?? '') !== ':') {
                throw new NoteBackupException('The backup contains invalid JSON.', 400, 'invalid_json');
            }

            $offset++;
            $this->parseValue($json, $offset, $depth);
            $this->skipWhitespace($json, $offset);
            $character = $json[$offset] ?? '';

            if ($character === '}') {
                $offset++;

                return;
            }

            if ($character !== ',') {
                throw new NoteBackupException('The backup contains invalid JSON.', 400, 'invalid_json');
            }

            $offset++;
            $this->skipWhitespace($json, $offset);
        }
    }

    private function parseArray(string $json, int &$offset, int $depth): void
    {
        $offset++;
        $this->skipWhitespace($json, $offset);

        if (($json[$offset] ?? '') === ']') {
            $offset++;

            return;
        }

        while (true) {
            $this->parseValue($json, $offset, $depth);
            $this->skipWhitespace($json, $offset);
            $character = $json[$offset] ?? '';

            if ($character === ']') {
                $offset++;

                return;
            }

            if ($character !== ',') {
                throw new NoteBackupException('The backup contains invalid JSON.', 400, 'invalid_json');
            }

            $offset++;
            $this->skipWhitespace($json, $offset);
        }
    }

    private function readString(string $json, int &$offset): string
    {
        $start = $offset++;

        while ($offset < strlen($json)) {
            if ($json[$offset] === '\\') {
                $offset += 2;

                continue;
            }

            if ($json[$offset] === '"') {
                $offset++;

                try {
                    $value = json_decode(substr($json, $start, $offset - $start), true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new NoteBackupException('The backup contains invalid JSON.', 400, 'invalid_json', previous: $exception);
                }

                if (! is_string($value)) {
                    throw new NoteBackupException('The backup contains invalid JSON.', 400, 'invalid_json');
                }

                return $value;
            }

            $offset++;
        }

        throw new NoteBackupException('The backup contains invalid JSON.', 400, 'invalid_json');
    }

    private function skipWhitespace(string $json, int &$offset): void
    {
        while ($offset < strlen($json) && str_contains(" \t\r\n", $json[$offset])) {
            $offset++;
        }
    }
}
