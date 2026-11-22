@props([
    'label',
    'action',
    'pressed' => null,
    'glyph' => null,
])

<button
    type="button"
    @mousedown.prevent
    @click="{{ $action }}"
    @if (! is_null($pressed))
        :aria-pressed="{{ $pressed }} ? 'true' : 'false'"
    @endif
    aria-label="{{ $label }}"
    title="{{ $label }}"
    {{ $attributes->class('inline-flex min-h-6 min-w-6 items-center justify-center rounded px-3 py-1 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800') }}
>
    @if ($glyph === 'Left')
        <svg class="size-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" aria-hidden="true">
            <path d="M2 3.5h12M2 6.5h8M2 9.5h12M2 12.5h8" />
        </svg>
    @elseif ($glyph === 'Center')
        <svg class="size-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" aria-hidden="true">
            <path d="M2 3.5h12M4 6.5h8M2 9.5h12M4 12.5h8" />
        </svg>
    @elseif ($glyph === 'Right')
        <svg class="size-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" aria-hidden="true">
            <path d="M2 3.5h12M6 6.5h8M2 9.5h12M6 12.5h8" />
        </svg>
    @elseif ($glyph === 'Justify')
        <svg class="size-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" aria-hidden="true">
            <path d="M2 3.5h12M2 6.5h12M2 9.5h12M2 12.5h12" />
        </svg>
    @else
        {{ $glyph ?? $slot }}
    @endif
</button>
