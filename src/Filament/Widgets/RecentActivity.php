<?php

namespace LicenseKit\Filament\Widgets;

use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use LicenseKit\Core\Models\ActivityEntry;
use LicenseKit\Filament\Resources\ActivityLog\ActivityEntryResource;

class RecentActivity extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table->heading('Recent activity')
            ->query(fn () => ActivityEntry::query())
            ->columns([
                TextColumn::make('description')->label('What happened')->state(fn (ActivityEntry $record): string => $record->description())->wrap(),
                TextColumn::make('created_at')->label('When')->since()->dateTimeTooltip(timezone: config('app.timezone')),
            ])
            ->headerActions([Action::make('all_activity')->label('View all')->link()->url(fn (): string => ActivityEntryResource::getUrl())])
            ->defaultSort('id', 'desc')->paginated(false)->modifyQueryUsing(fn ($query) => $query->limit(8))
            ->emptyStateHeading('No activity yet')->emptyStateIcon('heroicon-o-clock');
    }
}
