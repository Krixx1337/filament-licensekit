<?php

namespace LicenseKit\Filament\Pages;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\CodeEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Js;
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
use Phiki\Phiki;

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
            Callout::make('This is the default API, a simple example')->info()->icon(Heroicon::OutlinedLightBulb)
                ->description('It works out of the box. For a product you ship, we recommend your own protocol: '
                    .'LicenseKit still runs every check, and a protocol only your app speaks is harder to bypass.')
                ->actions([Action::make('own_protocol')->label('Build your own')->openUrlInNewTab()
                    ->url('https://github.com/Krixx1337/filament-licensekit/blob/main/docs/client-api.md#build-your-own-recommended')]),
            Flex::make([
                $this->status('Default API (example)', config('licensekit.client_enabled') === true, 'On', 'Off'),
                $this->status('Signing key', $publicKey !== null, 'Ready', 'Missing: run php artisan licensekit:install'),
                $this->status('First app login', LoaderSession::where('product_id', $product->id)->exists(), 'Seen', 'Not yet'),
            ])->from('md'),
            Section::make('1. Copy your public key')->icon(Heroicon::OutlinedKey)
                ->description('Paste it into your app. It proves that answers really come from your server.')
                ->schema([$this->chip('public_key', $publicKey, 'Public key copied')]),
            Section::make('2. Your app talks to these addresses')->icon(Heroicon::OutlinedGlobeAlt)
                ->description('Every address starts with the base address. The example code already uses it.')
                ->schema([
                    $this->chip('base', $base, 'Base address copied')->label('Base address')->hiddenLabel(false),
                    RepeatableEntry::make('endpoints')->hiddenLabel()->contained(false)
                        ->state(collect(['login' => 'Sign in', 'heartbeat' => 'Check access, every 60 seconds', 'redeem' => 'Redeem a key',
                            'move-device' => 'Move the license to a new PC'])->map(fn (string $what, string $path): array => ['what' => $what, 'path' => 'POST /'.$path])
                            ->values()->all())
                        ->table([TableColumn::make('What it does'), TableColumn::make('Address')])
                        ->schema([TextEntry::make('what'), TextEntry::make('path')->fontFamily(FontFamily::Mono)->badge()->color('gray')]),
                ]),
            Section::make('3. Example code')->icon(Heroicon::OutlinedCodeBracket)
                ->description('Pick your language. Only run your software while the heartbeat says access is true.')
                ->schema([Tabs::make('languages')->tabs($this->codeTabs($base, $publicKey))]),
            Section::make('What customers may see')->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)->collapsible()->collapsed()
                ->description('Messages your app can show, and what each one means.')
                ->schema([KeyValueEntry::make('errors')->hiddenLabel()->state(self::ERRORS)->keyLabel('Code')->valueLabel('What it means')]),
            Section::make('For your developer')->icon(Heroicon::OutlinedWrenchScrewdriver)->collapsible()->collapsed()->schema([
                Text::make('Own protocol: call the stable PHP services from your controllers (docs/client-api.md), then turn off client_enabled in config/licensekit.php.'),
                Text::make('Website two-factor login does not apply to app logins.'),
            ]),
        ]);
    }

    /**
     * The full client per language, one click away; with phiki/phiki installed, a highlighted five-line preview too.
     * The full client matters: it checks each answer's signature, nonce and time, which stops a fake server from granting access.
     *
     * @return list<Tab>
     */
    private function codeTabs(string $base, ?string $publicKey): array
    {
        $languages = [
            'python' => ['Python', 'client.py', 'python', 'A ready-made Python client. Needs the requests and pynacl packages.'],
            'csharp' => ['C#', 'Client.cs', 'csharp', 'A ready-made C# client. Needs the NSec.Cryptography package.'],
            'cpp' => ['C++', 'client.cpp', 'cpp', 'A ready-made C++17 client. Needs cpr, libsodium and nlohmann/json.'],
            'curl' => ['curl (testing only)', 'client.sh', 'shellscript', 'One test login from a terminal. It does not check signatures, so never ship it in your app.'],
        ];
        $highlight = class_exists(Phiki::class);
        $tabs = [];
        foreach ($languages as $key => [$label, $file, $grammar, $about]) {
            $full = $this->snippet($file, $base, $publicKey);
            $usage = __DIR__.'/../../../resources/snippets/usage/'.$file;
            $preview = is_file($usage) ? trim(str_replace("\r\n", "\n", (string) file_get_contents($usage))) : $full;
            $schema = [Text::make($about.($key === 'curl' ? '' : ' It signs in, redeems keys, sends the heartbeat and checks that every answer really came from your server.'))];
            // Without phiki/phiki a plain code block looks rough, so the code is only shown when it can be highlighted.
            if ($highlight) {
                $schema[] = CodeEntry::make('code_'.$key)->hiddenLabel()->state($preview)->grammar($grammar)->copyable()->copyMessage('Copied');
            }
            $schema[] = Actions::make([
                Action::make('copy_'.$key)->label($key === 'curl' ? 'Copy command' : 'Copy full example')->icon(Heroicon::OutlinedClipboardDocument)
                    ->alpineClickHandler('window.navigator.clipboard.writeText('.Js::from($full).'); $tooltip('.Js::from('Copied').', { theme: $store.theme, timeout: 2000 })'),
                Action::make('download_'.$key)->label('Download '.$file)->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                    ->action(fn () => response()->streamDownload(function () use ($full): void {
                        echo $full;
                    }, $file, ['Cache-Control' => 'private, no-store'])),
            ])->key('code_actions_'.$key);
            // Explicit keys: Filament keys tabs by label slug, and "C#" and "C++" both become "c".
            $tabs[] = Tab::make($label)->key('snippet-'.$key)->schema($schema);
        }

        return $tabs;
    }

    /** A short copyable value shown as a chip with a copy icon. */
    private function chip(string $name, ?string $value, string $copied): TextEntry
    {
        return TextEntry::make($name)->hiddenLabel()->state($value ?? 'Not set up yet')->fontFamily(FontFamily::Mono)->badge()->color('gray')->size(TextSize::Medium)
            ->icon($value === null ? null : Heroicon::OutlinedClipboardDocument)->iconPosition('after')
            ->copyable($value !== null)->copyMessage($copied)->tooltip($value === null ? null : 'Click to copy');
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
        return trim(str_replace(['__BASE__', '__PUBLIC_KEY__', "\r\n"], [$base, $publicKey ?? 'RUN_LICENSEKIT_INSTALL', "\n"],
            (string) file_get_contents(__DIR__.'/../../../resources/snippets/'.$file)));
    }
}
