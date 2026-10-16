<?php

namespace LicenseKit\Filament\Concerns;

use Filament\Actions\Action;
use LicenseKit\Filament\AdminAccess;

trait ShowsResult
{
    public function resultAction(): Action
    {
        return Action::make('result')->authorize(fn (): bool => AdminAccess::isAdmin())
            ->modalHeading(fn (array $arguments): string => $arguments['title'] ?? 'Generated keys')
            ->modalContent(fn (array $arguments) => view()->make('licensekit::result', ['text' => $arguments['text'] ?? '', 'token' => $arguments['token'] ?? false]))
            ->modalSubmitAction(false)->modalCancelActionLabel('Done')
            ->extraModalFooterActions(fn (array $arguments): array => ($arguments['token'] ?? false) ? [] : [
                Action::make('download')->label('Download .txt')->action(fn () => response()->streamDownload(
                    function () use ($arguments): void {
                        echo $arguments['text'] ?? '';
                    }, 'license-keys.txt', ['Cache-Control' => 'private, no-store'])),
            ]);
    }
}
