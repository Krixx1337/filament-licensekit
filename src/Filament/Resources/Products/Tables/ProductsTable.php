<?php

namespace LicenseKit\Filament\Resources\Products\Tables;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\Enums\ProductStatus;
use LicenseKit\Filament\Pages\ConnectYourApp;
use LicenseKit\Filament\Resources\Products\ProductResource;
use LicenseKit\Filament\Support\TimeLeft;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->weight(FontWeight::Medium)->searchable()
                    ->description(fn (Product $record): string => $record->plans->where('is_active', true)->pluck('name')->implode(' · ') ?: 'No plans on sale'),
                TextColumn::make('status')->badge()->state(fn (Product $record): ProductStatus => ProductStatus::from($record->status)),
                TextColumn::make('free_from')->label('Free weekend')->badge()->color('info')->icon(Heroicon::OutlinedGift)->placeholder('—')
                    ->state(fn (Product $record): ?string => match (true) {
                        $record->freeAccessActive() => 'On now, until '.TimeLeft::date($record->free_until),
                        $record->free_from?->isFuture() => 'From '.TimeLeft::date($record->free_from),
                        default => null,
                    })->visibleFrom('md'),
                TextColumn::make('active_licenses_count')->label('Active licenses')->numeric(),
                TextColumn::make('slug')->label('Web address name')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([LicensingActions::createKeys(), LicensingActions::addTime(),
                    Action::make('connect')->label('Connect your app')->icon(Heroicon::OutlinedCodeBracket)
                        ->url(fn (Product $record): string => ConnectYourApp::getUrl(['product' => $record->id]))]),
            ])
            ->recordUrl(fn (Product $record): ?string => ProductResource::canEdit($record) ? ProductResource::getUrl('edit', ['record' => $record]) : null)
            ->emptyStateHeading('Create your first product')->emptyStateIcon('heroicon-o-cube')
            ->emptyStateDescription('A product is what you sell, with one or more plans such as Monthly or Lifetime.')
            ->emptyStateActions([CreateAction::make()->label('Create product')->icon(Heroicon::OutlinedPlus)])
            ->defaultSort('id', 'desc');
    }
}
