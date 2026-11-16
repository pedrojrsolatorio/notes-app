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
    {{ $glyph ?? $slot }}
</button>
