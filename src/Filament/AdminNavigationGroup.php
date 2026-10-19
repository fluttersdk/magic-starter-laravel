<?php

namespace FlutterSdk\MagicStarter\Filament;

use Filament\Support\Contracts\HasLabel;

/**
 * The staff panel's navigation groups, in the order the sidebar lists them.
 *
 * Filament orders enum groups by case order, so this list IS the sidebar order:
 * who the customers are first, then what they asked, what they paid, what the
 * AI did for them, what was announced to them, and how the system is running.
 * A resource names its group through `$navigationGroup` and its place inside it
 * through `$navigationSort`; an application that wants every package resource
 * under one heading still passes `MagicStarterPlugin::navigationGroup()`.
 */
enum AdminNavigationGroup: string implements HasLabel
{
    case Customers = 'customers';
    case Support = 'support';
    case Billing = 'billing';
    case Ai = 'ai';
    case Communication = 'communication';
    case Operations = 'operations';

    /**
     * Translated on every render, so the label follows the request's locale
     * rather than the one the panel was built in.
     */
    public function getLabel(): string
    {
        return (string) __("magic-starter::admin.navigation_groups.{$this->value}");
    }
}
