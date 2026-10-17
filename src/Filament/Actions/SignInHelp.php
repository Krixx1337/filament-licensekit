<?php

namespace LicenseKit\Filament\Actions;

use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use LicenseKit\Core\Actions\SignInCheck;
use LicenseKit\Core\Actions\SignInProblems;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\ActivityEntry;
use LicenseKit\Core\Models\License;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Support\PcHistory;
use LicenseKit\Filament\Support\TimeLeft;

/** "Why can't they sign in?": what the app would say now, recent refusals and a reply to paste into the support chat. */
final class SignInHelp
{
    public static function make(): Action
    {
        return Action::make('sign_in_help')->label("Why can't they sign in?")->icon(Heroicon::OutlinedQuestionMarkCircle)->color('gray')
            ->authorize(fn (): bool => AdminAccess::isSupport())->slideOver()->modalHeading("Why can't they sign in?")
            ->modalDescription('What your app would tell this customer right now, from their locked PC. Nothing is changed.')
            ->schema(fn (License $record): array => self::schema($record))
            ->modalSubmitAction(false)->modalCancelActionLabel('Close');
    }

    /** @return array<int, mixed> */
    private static function schema(License $license): array
    {
        [$allowed, $title, $reply] = self::verdict($license);
        $changes = PcHistory::recentChanges($license);

        return [
            Callout::make($title)->status($allowed ? 'success' : 'danger')
                ->icon($allowed ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedXCircle)
                ->description($allowed ? 'If they still cannot sign in, check the recent problems below, their password, or the app version.' : null),
            TextEntry::make('reply')->label('Reply to the customer')->state($reply)->copyable()->copyMessage('Reply copied')
                ->icon(Heroicon::OutlinedClipboardDocument)->iconPosition('after'),
            RepeatableEntry::make('problems')->label('Recent sign-in problems')->placeholder('No problems logged.')
                ->state(app(SignInProblems::class)->recent($license)->map(fn (ActivityEntry $entry): array => [
                    'when' => $entry->created_at->diffForHumans(),
                    'problem' => ucfirst(SignInProblems::reason((string) ($entry->meta['code'] ?? ''))),
                    'pc' => PcHistory::label($entry->meta['device'] ?? null),
                ])->all())
                ->table([TableColumn::make('When'), TableColumn::make('Problem'), TableColumn::make('PC')])
                ->schema([TextEntry::make('when'), TextEntry::make('problem'), TextEntry::make('pc')->fontFamily(FontFamily::Mono)]),
            TextEntry::make('pc_changes')->label('PC changes in the last '.PcHistory::WINDOW_DAYS.' days')->state($changes)
                ->color($changes >= PcHistory::FREQUENT_CHANGES ? 'danger' : null)
                ->helperText($changes >= PcHistory::FREQUENT_CHANGES ? 'Changing PC this often can mean a shared account.' : null),
        ];
    }

    /** @return array{0: bool, 1: string, 2: string} */
    private static function verdict(License $license): array
    {
        try {
            $access = app(SignInCheck::class)->forLicense($license);
            $until = $access->validUntil === null ? '' : ' until '.TimeLeft::date($access->validUntil);

            return [true, 'Their app would let them in'.$until.'.',
                'Your license is active'.$until.'. Please restart the app and sign in again. If it still fails, send us a screenshot of the error.'];
        } catch (LicensingException $exception) {
            return [false, 'Their app would refuse: '.SignInProblems::reason($exception->error).'.', $exception->friendlyMessage()];
        }
    }
}
