<?php

namespace LicenseKit\Filament\Widgets;

use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use LicenseKit\Core\Models\License;
use LicenseKit\Filament\Enums\LicenseState;
use LicenseKit\Filament\Resources\Licenses\LicenseResource;
use LicenseKit\Filament\Support\TimeLeft;

/** Licenses a seller may want to act on: running out soon, just expired, or suspended. */
class NeedsAttention extends TableWidget
{
    use DashboardFilters;
    use InteractsWithPageFilters;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table->heading('Needs attention')
            ->description('Running out this week, expired in the last 3 days, or suspended.')
            ->query(fn () => $this->licenses()->with(['user', 'product'])->where(fn ($query) => $query
                ->where(fn ($q) => $q->effectiveStatus('expiring'))
                ->orWhere(fn ($q) => $q->effectiveStatus('expired')->where('expires_at', '>=', now()->subDays(3)->utc()->format('Y-m-d H:i:s')))
                ->orWhere(fn ($q) => $q->effectiveStatus(License::SUSPENDED))))
            ->columns([
                TextColumn::make('user.'.config('licensekit.user_label'))->label('Customer')->placeholder('Deleted user')->weight(FontWeight::Medium)
                    ->description(fn (License $record): string => $record->product->name),
                TextColumn::make('state')->label('Issue')->badge()->state(fn (License $record): LicenseState => LicenseState::for($record)),
                TextColumn::make('expires_at')->label('When')->state(fn (License $record): string => TimeLeft::for($record))
                    ->tooltip(fn (License $record): ?string => $record->expires_at === null ? null : TimeLeft::date($record->expires_at)),
            ])
            ->recordUrl(fn (License $record): string => LicenseResource::getUrl('view', ['record' => $record]))
            ->defaultSort('expires_at')->paginated([5])->defaultPaginationPageOption(5)
            ->emptyStateHeading('Nothing needs attention')->emptyStateDescription('No licenses are running out or suspended right now.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
