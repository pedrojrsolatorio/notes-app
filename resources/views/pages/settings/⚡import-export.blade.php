<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Backup and restore')] class extends Component {
}; ?>

<section class="w-full" x-data="noteBackupWorkflow()" x-bind:aria-busy="busy !== null">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Backup and restore') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Backup and restore')" :subheading="__('Keep a private copy of your notes and restore it without replacing your current work.')">
        <div class="my-6 space-y-8">
            <section aria-labelledby="backup-export-heading" class="space-y-4">
                <div>
                    <flux:heading level="3" id="backup-export-heading">{{ __('Export a backup') }}</flux:heading>
                    <flux:text>{{ __('Your notes, folders, and attachments are encrypted in one archive.') }}</flux:text>
                </div>

                <form x-ref="exportForm" action="{{ route('import-export.export') }}" @submit.prevent="exportBackup($event.currentTarget)" class="space-y-4">
                    @csrf

                    <flux:input
                        name="password"
                        type="password"
                        id="backup-export-password"
                        :label="__('Backup password')"
                        aria-describedby="backup-export-password-error"
                        autocomplete="new-password"
                        required
                    />
                    <p id="backup-export-password-error" x-show="fieldErrors.password" x-text="fieldErrors.password?.[0]" role="alert" class="text-sm text-red-600 dark:text-red-400"></p>

                    <flux:input
                        name="password_confirmation"
                        type="password"
                        id="backup-export-confirmation"
                        :label="__('Confirm backup password')"
                        aria-describedby="backup-export-confirmation-error"
                        autocomplete="new-password"
                        required
                    />
                    <p id="backup-export-confirmation-error" x-show="fieldErrors.password_confirmation" x-text="fieldErrors.password_confirmation?.[0]" role="alert" class="text-sm text-red-600 dark:text-red-400"></p>

                    <flux:button variant="primary" type="submit" x-bind:disabled="busy !== null">
                        <span x-text="busy === 'export' ? '{{ __('Preparing backup...') }}' : '{{ __('Download encrypted backup') }}'"></span>
                    </flux:button>
                </form>

                <p x-show="exportStatus" x-text="exportStatus" role="status" aria-live="polite" class="text-sm text-zinc-600 dark:text-zinc-300"></p>
            </section>

            <flux:separator />

            <section aria-labelledby="backup-import-heading" class="space-y-4">
                <div>
                    <flux:heading level="3" id="backup-import-heading">{{ __('Restore a backup') }}</flux:heading>
                    <flux:text>{{ __('Restore adds missing notes and attachments. It never replaces existing notes.') }}</flux:text>
                </div>

                <form x-ref="previewForm" action="{{ route('import-export.preview') }}" @submit.prevent="previewBackup($event.currentTarget)" class="space-y-4">
                    @csrf

                    <flux:input
                        name="archive"
                        type="file"
                        id="backup-archive"
                        accept=".zip,application/zip"
                        :label="__('Backup archive')"
                        aria-describedby="backup-archive-error"
                        required
                    />
                    <p id="backup-archive-error" x-show="fieldErrors.archive" x-text="fieldErrors.archive?.[0]" role="alert" class="text-sm text-red-600 dark:text-red-400"></p>

                    <flux:input
                        name="password"
                        type="password"
                        id="backup-preview-password"
                        :label="__('Backup password')"
                        aria-describedby="backup-preview-password-error"
                        autocomplete="new-password"
                        required
                    />
                    <p id="backup-preview-password-error" x-show="fieldErrors.password" x-text="fieldErrors.password?.[0]" role="alert" class="text-sm text-red-600 dark:text-red-400"></p>

                    <flux:button variant="primary" type="submit" x-bind:disabled="busy !== null">
                        <span x-text="busy === 'preview' ? '{{ __('Validating backup...') }}' : '{{ __('Review backup') }}'"></span>
                    </flux:button>
                </form>

                <p x-show="previewStatus" x-text="previewStatus" role="status" aria-live="polite" class="text-sm text-zinc-600 dark:text-zinc-300"></p>

                <div x-show="preview" x-cloak class="space-y-4">
                    <div>
                        <flux:heading level="4">{{ __('Backup preview') }}</flux:heading>
                        <flux:text>
                            {{ __('Review the items below. Enter the password again to restore this backup.') }}
                            <span x-show="expiresAt" x-text="`{{ __('Preview expires at') }} ${expiresAt}`"></span>
                        </flux:text>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-start text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <th scope="col" class="py-2 pe-4">{{ __('Items') }}</th>
                                    <th scope="col" class="py-2 pe-4">{{ __('New') }}</th>
                                    <th scope="col" class="py-2 pe-4">{{ __('Reused') }}</th>
                                    <th scope="col" class="py-2">{{ __('Skipped') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="entity in ['folders', 'notes', 'attachments']" :key="entity">
                                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                        <th scope="row" class="py-2 pe-4 font-medium" x-text="entityLabels[entity]"></th>
                                        <td class="py-2 pe-4" x-text="preview.counts[entity].created"></td>
                                        <td class="py-2 pe-4" x-text="preview.counts[entity].reused"></td>
                                        <td class="py-2" x-text="preview.counts[entity].skipped"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <form x-ref="restoreForm" action="{{ route('import-export.import') }}" data-cancel-url="{{ route('import-export.cancel') }}" @submit.prevent="restoreBackup($event.currentTarget)" class="space-y-4">
                        @csrf
                        <input type="hidden" name="preview_token" x-bind:value="previewToken">

                        <flux:input
                            name="password"
                            type="password"
                            id="backup-restore-password"
                            :label="__('Enter the backup password again')"
                            aria-describedby="backup-restore-password-error"
                            autocomplete="new-password"
                            required
                        />
                        <p id="backup-restore-password-error" x-show="fieldErrors.password" x-text="fieldErrors.password?.[0]" role="alert" class="text-sm text-red-600 dark:text-red-400"></p>

                        <div class="flex flex-wrap gap-3">
                            <flux:button variant="primary" type="submit" x-bind:disabled="busy !== null">
                                <span x-text="busy === 'restore' ? '{{ __('Restoring backup...') }}' : '{{ __('Restore backup') }}'"></span>
                            </flux:button>
                            <flux:button type="button" variant="ghost" x-on:click="cancelPreview()" x-bind:disabled="busy !== null">
                                {{ __('Cancel preview') }}
                            </flux:button>
                        </div>
                    </form>
                </div>

                <section x-show="result" x-cloak aria-labelledby="backup-result-heading" class="space-y-3">
                    <flux:heading level="4" id="backup-result-heading">{{ __('Restore complete') }}</flux:heading>

                    <div class="overflow-x-auto">
                        <table class="w-full text-start text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <th scope="col" class="py-2 pe-4">{{ __('Items') }}</th>
                                    <th scope="col" class="py-2 pe-4">{{ __('Created') }}</th>
                                    <th scope="col" class="py-2 pe-4">{{ __('Reused') }}</th>
                                    <th scope="col" class="py-2">{{ __('Skipped') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="entity in ['folders', 'notes', 'attachments']" :key="entity">
                                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                        <th scope="row" class="py-2 pe-4 font-medium" x-text="entityLabels[entity]"></th>
                                        <td class="py-2 pe-4" x-text="result.counts[entity].created"></td>
                                        <td class="py-2 pe-4" x-text="result.counts[entity].reused"></td>
                                        <td class="py-2" x-text="result.counts[entity].skipped"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <div x-show="result.skipped_items.length > 0">
                        <flux:heading level="5">{{ __('Skipped items') }}</flux:heading>
                        <ul class="list-inside list-disc space-y-1 text-sm">
                            <template x-for="(item, index) in result.skipped_items" :key="`${item.entity_type}-${index}`">
                                <li>
                                    <span x-text="item.display_name"></span>
                                    <span x-text="reasonLabels[item.reason_code] ?? item.reason_code"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </section>

                <section x-show="invalidItems.length > 0" aria-labelledby="backup-invalid-heading" class="space-y-2">
                    <flux:heading level="4" id="backup-invalid-heading">{{ __('Items that need attention') }}</flux:heading>
                    <ul class="list-inside list-disc space-y-1 text-sm text-red-600 dark:text-red-400">
                        <template x-for="(item, index) in invalidItems" :key="`${item.entity_type}-${index}`">
                            <li>
                                <span x-text="item.display_name"></span>
                                <span x-text="item.reason"></span>
                            </li>
                        </template>
                    </ul>
                </section>

                <p x-show="error" x-text="error" role="alert" aria-live="assertive" class="text-sm text-red-600 dark:text-red-400"></p>
            </section>
        </div>
    </x-pages::settings.layout>
</section>