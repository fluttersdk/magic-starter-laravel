<?php

namespace FlutterSdk\MagicStarter\Filament\Resources\NewsletterSubscribers\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use FlutterSdk\MagicStarter\Filament\Resources\NewsletterSubscribers\NewsletterSubscriberResource;
use FlutterSdk\MagicStarter\Filament\Support\ContractAction;
use FlutterSdk\MagicStarter\Models\NewsletterSubscriber;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListNewsletterSubscribers extends ListRecords
{
    protected static string $resource = NewsletterSubscriberResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('magic-starter::admin_misc.newsletter.export.label'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn (Action $action): mixed => ContractAction::run(
                    $action,
                    fn (): StreamedResponse => $this->streamCsv(),
                    'newsletter.exported',
                    null,
                )),
        ];
    }

    /**
     * Every subscriber as a CSV, written row by row so the list size is not a
     * memory question.
     */
    protected function streamCsv(): StreamedResponse
    {
        $name = 'newsletter-subscribers-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['email', 'is_active', 'source', 'created_at'], ',', '"', '');

            foreach (NewsletterSubscriber::query()->lazyById() as $subscriber) {
                fputcsv($out, [
                    $this->safeCell($subscriber->email),
                    $subscriber->is_active ? '1' : '0',
                    $this->safeCell($subscriber->source),
                    $subscriber->created_at?->toIso8601String(),
                ], ',', '"', '');
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    /**
     * Subscribers type their own address and source, and a spreadsheet runs a
     * cell that starts with one of these characters as a formula.
     */
    protected function safeCell(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return str_contains("=+-@\t\r", $value[0]) ? "'" . $value : $value;
    }
}
