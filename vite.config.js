import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

// VITE_SKIP_FONTS=1 skips the webfont fetch for offline or sandbox builds.
const fontsConfigEnabled = process.env.VITE_SKIP_FONTS !== '1';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/offline-app.js',
                'resources/js/passkeys.js',
            ],
            refresh: true,
            fonts: fontsConfigEnabled
                ? [
                    bunny('Instrument Sans', {
                        weights: [400, 500, 600],
                    }),
                ]
                : [],
        }),
        tailwindcss(),
    ]),
    server: {
        cors: true,
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/storage/framework/views/**',
                '**/vendor/**',
            ],
        },
    },
});
