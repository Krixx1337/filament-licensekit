<?php

namespace LicenseKit\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Text;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use LicenseKit\Filament\AdminAccess;

/** Shows generated keys or a new store token once, with copy and download buttons. */
trait ShowsResult
{
    public function resultAction(): Action
    {
        return Action::make('result')->authorize(fn (): bool => AdminAccess::isAdmin())
            ->modalHeading(fn (array $arguments): string => $arguments['title'] ?? 'Your keys are ready')
            ->modalIcon(Heroicon::OutlinedCheckCircle)->modalIconColor('success')
            ->schema(fn (array $arguments): array => [
                Text::make(($arguments['token'] ?? false)
                    ? 'Copy this token now and give it to your developer. It will not be shown again.'
                    : 'Click the list to copy every key, or download them as a text file.'),
                TextEntry::make('result')->hiddenLabel()->fontFamily(FontFamily::Mono)
                    ->state(fn (): array => preg_split('/\R/', (string) ($arguments['text'] ?? '')) ?: [])
                    ->listWithLineBreaks()->limitList(25)->expandableLimitedList()
                    ->copyable()->copyableState((string) ($arguments['text'] ?? ''))
                    ->copyMessage(($arguments['token'] ?? false) ? 'Token copied' : 'All keys copied'),
            ])
            ->modalSubmitAction(false)->modalCancelActionLabel('Done')
            ->extraModalFooterActions(fn (array $arguments): array => ($arguments['token'] ?? false) ? [] : [
                Action::make('download')->label('Download .txt')->icon(Heroicon::OutlinedArrowDownTray)->action(fn () => response()->streamDownload(
                    function () use ($arguments): void {
                        echo $arguments['text'] ?? '';
                    }, 'license-keys.txt', ['Cache-Control' => 'private, no-store'])),
            ]);
    }
}
