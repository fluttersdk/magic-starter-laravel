<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\Users\Schemas;

use DateTimeZone;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use FlutterSdk\MagicStarter\Features;
use Illuminate\Database\Eloquent\Model;

/**
 * The user form: name and email, plus the profile fields whose features are on.
 *
 * Never a password, a two-factor secret or recovery codes. Pinned by
 * `UserResourceTest`, which inspects the resolved schema rather than the HTML.
 */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        $components = [
            TextInput::make('name')
                ->label(__('magic-starter::admin_users.fields.name'))
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->label(__('magic-starter::admin_users.fields.email'))
                ->email()
                ->required(static fn (?Model $record): bool => $record === null
                    || filled($record->getAttribute('email')))
                ->maxLength(255)
                ->unique(ignoreRecord: true),
        ];

        if (Features::hasExtendedProfileFeatures()) {
            $components[] = Select::make('locale')
                ->label(__('magic-starter::admin_users.fields.locale'))
                ->options(static::locales());
            $components[] = TextInput::make('phone')
                ->label(__('magic-starter::admin_users.fields.phone'))
                ->tel()
                ->maxLength(20);
        }

        if (Features::hasTimezoneOrExtendedProfileFeatures()) {
            $components[] = Select::make('timezone')
                ->label(__('magic-starter::admin_users.fields.timezone'))
                ->options(array_combine(
                    $timezones = DateTimeZone::listIdentifiers(),
                    $timezones,
                ))
                ->searchable();
        }

        return $schema->components($components);
    }

    /**
     * @return array<string, string>
     */
    protected static function locales(): array
    {
        $locales = (array) config('magic-starter.supported_locales', ['en']);

        return array_combine($locales, $locales);
    }
}
