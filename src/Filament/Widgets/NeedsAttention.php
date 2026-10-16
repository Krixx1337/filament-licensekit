<?php

namespace LicenseKit\Filament\Widgets;

use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use LicenseKit\Core\Models\License;
use LicenseKit\Filament\Enums\LicenseState;
use LicenseKit\Filament\Resources\Licenses\LicenseResource;
use LicenseKit\Filament\Support\PcHistory;
use LicenseKit\Filament\Support\TimeLeft;

/** Licenses a seller may want to act on: running out soon, just expired, suspended, or changing PC often. */
class NeedsAttention extends TableWidget
{
    use DashboardFilters;
    use InteractsWithPageFilters;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table->heading('Needs attention')
            ->description('Running out this week, expired in the last 3 days, suspended, or changing PC often.')
            ->query(fn () => $this->licenses()->with(['user', 'product'])->where(fn ($query) => $query
                ->where(fn ($q) => $q->effectiveStatus('expiring'))
                ->orWhere(fn ($q) => $q->effectiveStatus('expired')->where('expires_at', '>=', now()->subDays(3)->utc()->format('Y-m-d H:i:s')))
                ->orWhere(fn ($q) => $q->effectiveStatus(License::SUSPENDED))
                ->orWhereIn('id', PcHistory::frequentChangerIds())))
            ->columns([
                TextColumn::make('user.'.config('licensekit.user_label'))->label('Customer')->placeholder('Deleted user')->weight(FontWeight::Medium)
                    ->description(fn (License $record): string => $record->product->name),
                TextColumn::make('state')->label('Issue')->badge()
                    ->state(fn (License $record): LicenseState|string => self::changesPcOften($record) ? 'Changes PC often' : LicenseState::for($record))
                    ->color(fn ($state): string => $state instanceof LicenseState ? $state->getColor() : 'warning')
                    ->icon(fn ($state): Heroicon => $state instanceof LicenseState ? $state->getIcon() : Heroicon::OutlinedComputerDesktop),
                TextColumn::make('expires_at')->label('Details')
                    ->state(fn (License $record): string => self::changesPcOften($record)
                        ? PcHistory::recentChanges($record).' PC changes in '.PcHistory::WINDOW_DAYS.' days' : TimeLeft::for($record))
                    ->tooltip(fn (License $record): ?string => $record->expires_at === null ? null : TimeLeft::date($record->expires_at)),
            ])
            ->recordUrl(fn (License $record): string => LicenseResource::getUrl('view', ['record' => $record]))
            ->defaultSort('expires_at')->paginated([5])->defaultPaginationPageOption(5)
            ->emptyStateHeading('Nothing needs attention')->emptyStateDescription('No licenses are running out or suspended right now.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    /** Only flagged when nothing more urgent (running out, expired, suspended) explains the row. */
    private static function changesPcOften(License $license): bool
    {
        return in_array(LicenseState::for($license), [LicenseState::Active, LicenseState::Lifetime, LicenseState::FreeAccess], true)
            && PcHistory::recentChanges($license) >= PcHistory::FREQUENT_CHANGES;
    }
}
