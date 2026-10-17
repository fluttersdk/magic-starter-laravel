<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\AiPrompts\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use FlutterSdk\MagicStarter\Contracts\DeletesAiPromptOverrides;
use FlutterSdk\MagicStarter\Filament\Concerns\WritesThroughContracts;
use FlutterSdk\MagicStarter\Filament\Resources\AiPrompts\AiPromptOverrideResource;
use FlutterSdk\MagicStarter\Filament\Support\ContractAction;
use FlutterSdk\MagicStarter\Models\AiPromptOverride;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EditAiPromptOverride extends EditRecord
{
    use WritesThroughContracts;

    protected static string $resource = AiPromptOverrideResource::class;

    /**
     * The editor opens on the code default as it is now, which is what the contract compares the
     * save against: a default that changes while the page is open is refused, not overwritten.
     * A key the host no longer registers has no default to load.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['loaded_default_hash'] = AiPromptOverrideResource::definition((string) $data['key'])?->defaultHash();

        return $data;
    }

    /**
     * Plain actions rather than Filament's delete action: that one calls `$record->delete()`
     * and would skip the contract.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')
                ->label(__('magic-starter::admin_ai.prompts.actions.download'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->downloadBody()),
            Action::make('promoted')
                ->label(__('magic-starter::admin_ai.prompts.actions.promoted.label'))
                ->icon(Heroicon::OutlinedArchiveBoxXMark)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('magic-starter::admin_ai.prompts.actions.promoted.heading'))
                ->modalDescription(__('magic-starter::admin_ai.prompts.actions.promoted.description'))
                ->successNotificationTitle(__('magic-starter::admin_ai.prompts.actions.promoted.success'))
                ->successRedirectUrl(fn (): string => AiPromptOverrideResource::getUrl('index'))
                ->action(function (Action $action): void {
                    /** @var AiPromptOverride $override */
                    $override = $this->getRecord();
                    $actor = $this->panelActor();

                    ContractAction::run(
                        $action,
                        static fn () => app(DeletesAiPromptOverrides::class)->delete($actor, $override),
                        'ai_prompt.deleted',
                        $override,
                    );
                }),
        ];
    }

    /**
     * The stored body as `<key>.txt`, so it can be pasted into the code default. The key is
     * slugged with dots kept: a `/` or `\` in it would make the disposition header throw.
     */
    private function downloadBody(): StreamedResponse
    {
        /** @var AiPromptOverride $override */
        $override = $this->getRecord();

        return response()->streamDownload(
            static function () use ($override): void {
                echo $override->body;
            },
            Str::slug($override->key, '.') . '.txt',
            ['Content-Type' => 'text/plain'],
        );
    }
}
