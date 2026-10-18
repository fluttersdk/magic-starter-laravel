<?php

namespace FlutterSdk\MagicStarter\Http\Resources;

use FlutterSdk\MagicStarter\Enums\CampaignDiscountType;
use FlutterSdk\MagicStarter\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One campaign as a client reads it from `GET billing/campaign`.
 *
 * The headline and body carry every supported locale: a locale staff left
 * blank takes the default locale's text, so a client never falls back on its
 * own. The discount is the coupon snapshot taken when the code was checked on
 * save, never a live Stripe read.
 *
 * @mixin Campaign
 */
class CampaignResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'products' => array_values($this->products),
            'discount' => [
                'type' => $this->discount_type?->value,
                'value' => $this->discountValue(),
                'currency' => $this->discount_currency,
                'currency_options' => $this->currencyOptions(),
            ],
            'headline' => $this->localisedText($this->headline),
            'body' => $this->localisedText($this->body),
        ];
    }

    /**
     * The snapshot value as a whole number when it is one: the column is a
     * decimal, and a client comparing the wire against a fixture would read
     * `20.0` and `20` as different documents.
     */
    private function discountValue(): int|float|null
    {
        $value = $this->discount_value;

        if ($value === null || floor($value) !== $value) {
            return $value;
        }

        return (int) $value;
    }

    /**
     * An amount discount's figure in each further currency, keyed like `currency`
     * (uppercase ISO) in the same minor unit as `value`; null for a percent
     * discount or an amount given in one currency only.
     *
     * @return array<string, int>|null
     */
    private function currencyOptions(): ?array
    {
        if ($this->discount_type !== CampaignDiscountType::AMOUNT || ($this->currency_options ?? []) === []) {
            return null;
        }

        return $this->currency_options;
    }

    /**
     * Fill every supported locale, taking the default locale's text for a blank one.
     *
     * @param  array<string, string|null>  $text
     * @return array<string, string>
     */
    private function localisedText(array $text): array
    {
        $fallback = (string) ($text[config('magic-starter.defaults.locale')] ?? '');
        $filled = [];

        foreach (config('magic-starter.supported_locales') as $locale) {
            $filled[$locale] = filled($text[$locale] ?? null) ? $text[$locale] : $fallback;
        }

        return $filled;
    }
}
