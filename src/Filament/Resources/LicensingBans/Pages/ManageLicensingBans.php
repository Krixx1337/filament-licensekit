<?php

namespace LicenseKit\Filament\Resources\LicensingBans\Pages;

use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ManageRecords;
use LicenseKit\Core\Actions\ManageLicensingBans as ManageBans;
use LicenseKit\Core\Models\LicensingBan;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Concerns\DisplaysLicensingErrors;
use LicenseKit\Filament\Resources\LicensingBans\LicensingBanResource;

class ManageLicensingBans extends ManageRecords
{
    use DisplaysLicensingErrors;

    protected static string $resource = LicensingBanResource::class;

    public function boot(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    public function getSubheading(): string
    {
        return 'Bans deny key redemption and software access. License time continues. Website login, license viewing, support, and HWID reset remain available under host rules.';
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('create')->label('Create ban')->authorize(fn (): bool => AdminAccess::can('create', LicensingBan::class, static::getResource()))
            ->schema([
                LicensingActions::userSelect(),
                Select::make('product_id')->label('Product')->required()
                    ->options(fn (): array => Product::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
                Textarea::make('reason')->required()->maxLength(2000),
                DateTimePicker::make('expires_at')->label('Expiry (UTC)')->timezone('UTC')->helperText('Leave empty for a permanent ban.'),
            ])->action(function (array $data): void {
                $actor = AdminAccess::actor();
                app(ManageBans::class)->create(ConfiguredUserModel::className()::findOrFail($data['user_id']),
                    Product::query()->whereKey($data['product_id'])->firstOrFail(), $data['reason'], $actor,
                    empty($data['expires_at']) ? null : CarbonImmutable::parse($data['expires_at'], 'UTC'));
            })];
    }
}
