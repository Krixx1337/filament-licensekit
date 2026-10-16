<?php

namespace LicenseKit\Filament\Pages;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use LicenseKit\Core\Actions\AccessPolicy;
use LicenseKit\Core\Actions\DeviceBans;
use LicenseKit\Core\Actions\ResponseSigning;
use LicenseKit\Core\Contracts\DeviceMatcher;
use LicenseKit\Core\Exceptions\LicensingException;
use LicenseKit\Core\Models\LoaderSession;
use LicenseKit\Core\Models\Product;
use LicenseKit\Core\Users\ConfiguredUserModel;
use LicenseKit\Filament\Actions\LicensingActions;
use LicenseKit\Filament\Navigation;
use LicenseKit\Filament\Resources\Products\ProductResource;
use LicenseKit\Filament\Support\TimeLeft;
use Livewire\Attributes\Url;

/** Everything a seller's developer needs to make their app talk to LicenseKit, per product. */
class ConnectYourApp extends LicenseKitPage
{
    protected static string|\UnitEnum|null $navigationGroup = Navigation::SETUP;

    protected static ?int $navigationSort = 10;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-code-bracket';

    protected static ?string $slug = 'licensekit/connect-your-app';

    protected static ?string $navigationLabel = 'Connect your app';

    protected static ?string $title = 'Connect your app';

    protected ?string $subheading = 'Give this page to whoever builds your app. It has the addresses, the public key and example code.';

    #[Url(as: 'product')]
    public ?int $productId = null;

    /** Messages customers may see in your app, and what they should do. */
    private const ERRORS = [
        'invalid_credentials' => 'Wrong email or password. Ask them to sign in again.',
        'no_license / expired' => 'They need to redeem a key.',
        'device_mismatch' => 'This license is locked to another PC. They can move it if the waiting period has passed.',
        'move_cooldown' => 'They moved PCs recently and must wait, or ask you to reset the PC.',
        'suspended / cancelled / device_banned' => 'Access is blocked. They should contact you.',
        'paused' => 'Their saved time is waiting until you resume it.',
        'email_not_verified' => 'They must verify their email on your website first.',
        'maintenance' => 'You put the product in maintenance. They should try again later.',
    ];

    public function getProduct(): ?Product
    {
        return Product::query()->whereKey($this->productId)->first() ?? Product::query()->orderBy('name')->first();
    }

    protected function getHeaderActions(): array
    {
        $products = Product::query()->orderBy('name')->get();

        return [
            ActionGroup::make($products->map(fn (Product $product): Action => Action::make('product_'.$product->id)->label($product->name)
                ->url(static::getUrl(['product' => $product->id])))->all())
                ->label(fn (): string => 'Product: '.($this->getProduct()->name ?? 'none'))->button()->color('gray')
                ->icon(Heroicon::OutlinedChevronDown)->iconPosition('after')->visible($products->count() > 1),
            Action::make('check_customer')->label('Check a customer')->icon(Heroicon::OutlinedMagnifyingGlass)
                ->modalDescription('See what your app would tell this customer right now. Nothing is changed.')
                ->schema([
                    LicensingActions::userSelect()->label('Customer'),
                    Select::make('product_id')->label('Product')->options(fn () => Product::pluck('name', 'id'))->default(fn () => $this->getProduct()?->id)->required(),
                    TextInput::make('device')->label('PC fingerprint (optional)')->maxLength(1024),
                ])->modalSubmitActionLabel('Check')->action(fn (array $data) => $this->check($data)),
        ];
    }

    /** @param array<string, mixed> $data */
    private function check(array $data): void
    {
        $user = ConfiguredUserModel::className()::findOrFail($data['user_id']);
        $product = Product::whereKey($data['product_id'])->firstOrFail();
        try {
            $access = app(AccessPolicy::class)->authorize($user, $product);
            if (! empty($data['device'])) {
                app(DeviceBans::class)->assertAllowed($product, $data['device']);
                if ($product->device_lock && $access->license?->device !== null && ! app(DeviceMatcher::class)->matches($product, $access->license->device, $data['device'])) {
                    throw new LicensingException('device_mismatch');
                }
            }
            Notification::make()->success()->title($access->validUntil === null ? 'Allowed: lifetime access' : 'Allowed until '.TimeLeft::date($access->validUntil))->send();
        } catch (LicensingException $exception) {
            Notification::make()->danger()->title('Not allowed')->body($exception->friendlyMessage())->send();
        }
    }

    public function content(Schema $schema): Schema
    {
        $product = $this->getProduct();
        if ($product === null) {
            return $schema->components([Callout::make('Create a product first')->info()->icon(Heroicon::OutlinedCube)
                ->description('Each product gets its own app address.')
                ->actions([Action::make('create_product')->label('Create product')->url(ProductResource::getUrl('create'))])]);
        }
        $base = url(config('licensekit.client_prefix').'/'.$product->slug);
        $publicKey = $this->publicKey();

        return $schema->components([
            Flex::make([
                $this->status('Loader API', config('licensekit.client_enabled') === true, 'On', 'Off'),
                $this->status('Signing key', $publicKey !== null, 'Ready', 'Missing: run php artisan licensekit:install'),
                $this->status('First app login', LoaderSession::where('product_id', $product->id)->exists(), 'Seen', 'Not yet'),
            ])->from('md'),
            Section::make('1. Copy your public key')->icon(Heroicon::OutlinedKey)
                ->description('Paste it into your app. It proves that answers really come from your server.')
                ->schema([TextEntry::make('public_key')->hiddenLabel()->state($publicKey ?? 'Not set up yet')->fontFamily(FontFamily::Mono)
                    ->copyable($publicKey !== null)->copyMessage('Public key copied')]),
            Section::make('2. Your app talks to these addresses')->icon(Heroicon::OutlinedGlobeAlt)->columns(['md' => 2])
                ->description('Send every request as JSON with a random "nonce". The example code below does this for you.')
                ->schema(collect(['login' => 'Sign in', 'heartbeat' => 'Check every minute', 'redeem' => 'Redeem a key', 'move-device' => 'Move to a new PC'])
                    ->map(fn (string $label, string $path) => TextEntry::make('endpoint_'.$path)->label($label)->state('POST '.$base.'/'.$path)
                        ->fontFamily(FontFamily::Mono)->copyable()->copyableState($base.'/'.$path)->copyMessage('Address copied'))->values()->all()),
            Section::make('3. Example code')->icon(Heroicon::OutlinedCodeBracket)
                ->description('Copy one into your app. Only run your software while the heartbeat says access is true.')
                ->schema([Tabs::make('languages')->tabs(collect(['Python' => 'client.py', 'C#' => 'Client.cs', 'C++' => 'client.cpp', 'curl (testing only)' => 'client.sh'])
                    // Explicit keys: Filament keys tabs by label slug, and "C#" and "C++" both become "c".
                    ->map(fn (string $file, string $language): Tab => Tab::make($language)->key('snippet-'.str_replace('.', '-', strtolower($file)))->schema([
                        TextEntry::make('snippet_'.md5($file))->hiddenLabel()->markdown()
                            ->state("```\n".$this->snippet($file, $base, $publicKey)."\n```")
                            ->copyable()->copyableState($this->snippet($file, $base, $publicKey))->copyMessage('Code copied'),
                    ]))->values()->all())]),
            Section::make('What customers may see')->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)->collapsible()->collapsed()
                ->description('Messages your app can show, and what each one means.')
                ->schema([KeyValueEntry::make('errors')->hiddenLabel()->state(self::ERRORS)->keyLabel('Code')->valueLabel('What it means')]),
            Section::make('For your developer')->icon(Heroicon::OutlinedWrenchScrewdriver)->collapsible()->collapsed()->schema([
                Text::make('Want your own protocol? Turn off client_enabled in config/licensekit.php and use the PHP services in docs/client-api.md.'),
                Text::make('Website two-factor login does not apply to app logins.'),
            ]),
        ]);
    }

    private function status(string $label, bool $good, string $yes, string $no): TextEntry
    {
        return TextEntry::make(md5($label))->label($label)->state($good ? $yes : $no)->badge()->color($good ? 'success' : 'warning')
            ->icon($good ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedExclamationTriangle);
    }

    private function publicKey(): ?string
    {
        try {
            return app(ResponseSigning::class)->publicKey();
        } catch (LicensingException) {
            return null;
        }
    }

    private function snippet(string $file, string $base, ?string $publicKey): string
    {
        return trim(str_replace(['__BASE__', '__PUBLIC_KEY__'], [$base, $publicKey ?? 'RUN_LICENSEKIT_INSTALL'],
            (string) file_get_contents(__DIR__.'/../../../resources/snippets/'.$file)));
    }
}
