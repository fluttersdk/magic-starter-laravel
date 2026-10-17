<?php

namespace FlutterSdk\MagicStarter\Http\Resources;

use FlutterSdk\MagicStarter\Models\AppRelease;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One published release as a client reads it.
 *
 * Every item carries its text in every supported locale: a locale staff left
 * missing, null or empty takes the default locale's text, so a client never
 * has to fall back on its own.
 *
 * @mixin AppRelease
 */
class AppReleaseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'version' => $this->version,
            'published_at' => $this->published_at?->toJSON(),
            'ios_build' => $this->ios_build,
            'android_build' => $this->android_build,
            'items' => array_map(fn (array $item): array => [
                'type' => $item['type'],
                'text' => $this->localisedText($item['text']),
            ], $this->items),
        ];
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
