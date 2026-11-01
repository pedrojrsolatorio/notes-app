<?php

namespace App\Services\NoteBackups;

use DOMDocument;
use DOMElement;
use DOMNode;
use Mews\Purifier\Facades\Purifier;
use RuntimeException;

class NoteBackupHtmlSanitizer
{
    private const array ALLOWED_ELEMENTS = [
        'p', 'h1', 'h2', 'h3', 'blockquote', 'pre', 'code', 'br', 'hr',
        'ul', 'ol', 'li', 'strong', 'b', 'em', 'i', 'u', 's', 'del',
        'mark', 'a', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'label',
        'input', 'span', 'div',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const array ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'target', 'rel'],
        'ul' => ['data-type'],
        'li' => ['data-type', 'data-checked', 'style'],
        'input' => ['type', 'disabled'],
        'th' => ['colspan', 'rowspan', 'colwidth'],
        'td' => ['colspan', 'rowspan', 'colwidth'],
        'p' => ['style'],
        'h1' => ['style'],
        'h2' => ['style'],
        'h3' => ['style'],
        'blockquote' => ['style'],
        'mark' => ['style'],
        'span' => ['style'],
        'div' => ['style'],
    ];

    private const array ALLOWED_TEXT_ALIGNMENTS = ['left', 'center', 'right', 'justify'];

    private const array ALLOWED_FONT_SIZES = ['12px', '14px', '16px', '18px', '20px', '24px', '28px', '32px'];

    public function clean(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $cleanHtml = Purifier::clean($html, 'note_backup');
        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrorSetting = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><div>'.$cleanHtml.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorSetting);

        if (! $loaded) {
            throw new RuntimeException('The sanitized backup content could not be parsed.');
        }

        $container = $document->documentElement;

        if (! $container instanceof DOMElement || strtolower($container->tagName) !== 'div') {
            throw new RuntimeException('The sanitized backup content has an invalid document structure.');
        }

        $this->sanitizeChildren($container);

        $result = '';

        foreach ($container->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return $result;
    }

    private function sanitizeChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (! in_array($tag, self::ALLOWED_ELEMENTS, true)) {
                $parent->removeChild($child);

                continue;
            }

            if ($tag === 'input' && ! $this->isDisabledTaskCheckbox($child)) {
                $parent->removeChild($child);

                continue;
            }

            $allowedAttributes = self::ALLOWED_ATTRIBUTES[$tag] ?? [];

            foreach (iterator_to_array($child->attributes) as $attribute) {
                $name = strtolower($attribute->name);

                if (! in_array($name, $allowedAttributes, true)) {
                    $child->removeAttributeNode($attribute);
                }
            }

            $this->sanitizeElementAttributes($child);
            $this->sanitizeChildren($child);
        }
    }

    private function isDisabledTaskCheckbox(DOMElement $input): bool
    {
        $label = $input->parentNode;
        $item = $label?->parentNode;

        return strtolower($input->getAttribute('type')) === 'checkbox'
            && $input->hasAttribute('disabled')
            && $label instanceof DOMElement
            && strtolower($label->tagName) === 'label'
            && $item instanceof DOMElement
            && strtolower($item->tagName) === 'li'
            && $item->getAttribute('data-type') === 'taskItem';
    }

    private function sanitizeElementAttributes(DOMElement $element): void
    {
        $tag = strtolower($element->tagName);

        if ($tag === 'a') {
            $href = trim($element->getAttribute('href'));
            $scheme = parse_url($href, PHP_URL_SCHEME);

            if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https', 'mailto'], true)) {
                $element->removeAttribute('href');
            }

            if ($element->hasAttribute('target') && $element->getAttribute('target') !== '_blank') {
                $element->removeAttribute('target');
            }

            if ($element->hasAttribute('rel')) {
                $element->setAttribute('rel', 'noopener noreferrer nofollow');
            }
        }

        if ($tag === 'ul' && $element->getAttribute('data-type') !== 'taskList') {
            $element->removeAttribute('data-type');
        }

        if ($tag === 'li') {
            if ($element->getAttribute('data-type') !== 'taskItem') {
                $element->removeAttribute('data-type');
                $element->removeAttribute('data-checked');
            } elseif (! in_array($element->getAttribute('data-checked'), ['true', 'false'], true)) {
                $element->removeAttribute('data-checked');
            }
        }

        if ($tag === 'th' || $tag === 'td') {
            foreach (['colspan', 'rowspan'] as $name) {
                if ($element->hasAttribute($name) && ! $this->isPositiveInteger($element->getAttribute($name))) {
                    $element->removeAttribute($name);
                }
            }

            if ($element->hasAttribute('colwidth') && ! preg_match('/\A[1-9]\d*(?:,[1-9]\d*)*\z/', $element->getAttribute('colwidth'))) {
                $element->removeAttribute('colwidth');
            }
        }

        if ($element->hasAttribute('style')) {
            $this->sanitizeStyle($element);
        }
    }

    private function sanitizeStyle(DOMElement $element): void
    {
        $styles = [];

        foreach (explode(';', $element->getAttribute('style')) as $declaration) {
            $parts = explode(':', $declaration, 2);

            if (count($parts) !== 2) {
                continue;
            }

            [$property, $value] = array_map('trim', $parts);
            $property = strtolower($property);
            $value = strtolower($value);

            $valid = match ($property) {
                'text-align' => in_array($value, self::ALLOWED_TEXT_ALIGNMENTS, true),
                'font-size' => in_array($value, self::ALLOWED_FONT_SIZES, true),
                'color', 'background-color' => (bool) preg_match('/\A#[0-9a-f]{6}\z/i', $value),
                default => false,
            };

            if ($valid) {
                $styles[$property] = $value;
            }
        }

        if ($styles === []) {
            $element->removeAttribute('style');

            return;
        }

        ksort($styles);
        $element->setAttribute('style', implode(';', array_map(
            fn (string $property, string $value): string => $property.': '.$value,
            array_keys($styles),
            array_values($styles),
        )));
    }

    private function isPositiveInteger(string $value): bool
    {
        return ctype_digit($value) && (int) $value > 0;
    }
}
