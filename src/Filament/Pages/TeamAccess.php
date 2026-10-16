<?php

namespace LicenseKit\Filament\Pages;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use LicenseKit\Filament\Navigation;

class TeamAccess extends LicenseKitPage
{
    protected static string|\UnitEnum|null $navigationGroup = Navigation::SETUP;

    protected static ?int $navigationSort = 30;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $slug = 'licensekit/team-access';

    protected static ?string $navigationLabel = 'Team access';

    protected static ?string $title = 'Team access';

    protected ?string $subheading = 'Who on your team can do what.';

    public function content(Schema $schema): Schema
    {
        return $schema->columns(['md' => 2])->components([
            Section::make('Admin')->icon(Heroicon::OutlinedShieldCheck)->description('Runs the shop.')->schema([
                TextEntry::make('admin')->hiddenLabel()->bulleted()->listWithLineBreaks()->state([
                    'Everything Support can do', 'Create and edit products', 'Generate keys and give licenses', 'Cancel licenses and keys',
                    'Ban PCs', 'Connect stores and your app',
                ]),
            ]),
            Section::make('Support')->icon(Heroicon::OutlinedLifebuoy)->description('Helps customers.')->schema([
                TextEntry::make('support')->hiddenLabel()->bulleted()->listWithLineBreaks()->state([
                    'Look up licenses, keys and the activity log', 'Add time, pause and resume', 'Suspend and unsuspend', 'Reset a customer\'s PC',
                ]),
            ]),
            Section::make('For your developer')->icon(Heroicon::OutlinedWrenchScrewdriver)->columnSpanFull()->collapsible()->schema([
                Text::make('Every panel user is an Admin until you change it. Define the licensekit.admin and licensekit.support gates in your app to choose who gets which role.'),
                Text::make('Your normal model policies, for example from Filament Shield, still apply on top.'),
            ]),
        ]);
    }
}
