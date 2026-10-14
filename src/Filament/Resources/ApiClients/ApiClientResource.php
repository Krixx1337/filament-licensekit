<?php

namespace LicenseKit\Filament\Resources\ApiClients;

use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LicenseKit\Core\Actions\ManageApiClients;
use LicenseKit\Core\Models\ApiClient;
use LicenseKit\Core\Models\Product;
use LicenseKit\Filament\AdminAccess;
use LicenseKit\Filament\Resources\ApiClients\Pages\ManageApiClients as ManageClientsPage;
use LicenseKit\Filament\Resources\LicensingResource;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApiClientResource extends LicensingResource
{
    protected static ?string $slug = 'licensekit/api-clients';

    protected static ?string $navigationLabel = 'API clients';

    protected static ?string $modelLabel = 'API client';

    protected static ?string $pluralModelLabel = 'API clients';

    protected static ?string $model = ApiClient::class;

    protected static bool $editable = true;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            Toggle::make('is_enabled')->default(true),
            Toggle::make('all_products')->default(false)->live()->helperText('Includes every current and future product. Otherwise only selected products are authorized.'),
            Select::make('allowed_product_ids')->label('Products')->options(fn (): array => Product::pluck('name', 'id')->all())->multiple()->searchable()->default([])
                ->visible(fn (Get $get): bool => ! $get('all_products'))
                ->dehydrated(fn (Get $get): bool => ! $get('all_products'))
                ->dehydrateStateUsing(fn (array $state): array => array_map('intval', $state)),
            Select::make('permissions')->options(ApiClient::permissionOptions())->multiple()->default([]),
            TextInput::make('maximum_quantity')->label('Maximum keys per request')->integer()->minValue(1)->maxValue(config('licensekit.maximum_batch_size'))->default(100)->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable()->toggleable(), IconColumn::make('is_enabled')->label('Enabled')->boolean()->toggleable(),
            IconColumn::make('all_products')->label('All products')->boolean()->toggleable(isToggledHiddenByDefault: true), TextColumn::make('maximum_quantity')->label('Max keys')->toggleable(isToggledHiddenByDefault: true)])->recordActions([
                EditAction::make()->using(fn (ApiClient $record, array $data): ApiClient => self::save($record, $data)),
                Action::make('rotate_token')->authorize(fn (ApiClient $record): bool => AdminAccess::can('rotateToken', $record, static::class))->label('Rotate management token')->requiresConfirmation()
                    ->schema([DateTimePicker::make('expires_at')->label('Expiry (UTC)')->timezone('UTC')->default(fn (): string => now('UTC')->addYear()->format('Y-m-d H:i:s'))->required()])
                    ->action(fn (ApiClient $record, array $data): StreamedResponse => self::execute(function (Model $actor) use ($record, $data): StreamedResponse {
                        $token = app(ManageApiClients::class)->rotate($record, CarbonImmutable::parse($data['expires_at'], 'UTC'), $actor);

                        return response()->streamDownload(function () use ($token): void {
                            echo $token;
                        }, 'management-token.txt', ['Cache-Control' => 'private, no-store']);
                    })),
                Action::make('revoke_tokens')->authorize(fn (ApiClient $record): bool => AdminAccess::can('revokeTokens', $record, static::class))->label('Revoke management tokens')->requiresConfirmation()
                    ->action(fn (ApiClient $record) => self::execute(fn (Model $actor) => app(ManageApiClients::class)->revoke($record, $actor))),
            ])->defaultSort('id', 'desc');
    }

    /** @param array<string, mixed> $data */
    public static function save(?ApiClient $client, array $data): ApiClient
    {
        return self::execute(fn (Model $actor): ApiClient => app(ManageApiClients::class)->save($client, $data, $actor));
    }

    /** @param array<string, mixed> $data */
    public static function createWithToken(array $data): StreamedResponse
    {
        return self::execute(function (Model $actor) use ($data): StreamedResponse {
            $clients = app(ManageApiClients::class);
            $client = $clients->save(null, $data, $actor);
            $token = $clients->rotate($client, CarbonImmutable::now('UTC')->addYear(), $actor, allowDisabled: true);

            return response()->streamDownload(function () use ($token): void {
                echo $token;
            }, 'management-token.txt', ['Cache-Control' => 'private, no-store']);
        });
    }

    /**
     * @template T
     *
     * @param  Closure(Model): T  $operation
     * @return T
     */
    private static function execute(Closure $operation): mixed
    {
        return DB::transaction(function () use ($operation) {
            abort_unless(self::canViewAny(), 403);

            return $operation(AdminAccess::actor());
        });
    }

    public static function getPages(): array
    {
        return ['index' => ManageClientsPage::route('/')];
    }
}
