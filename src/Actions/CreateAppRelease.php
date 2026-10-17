<?php

namespace FlutterSdk\MagicStarter\Actions;

use FlutterSdk\MagicStarter\Contracts\CreatesAppReleases;
use FlutterSdk\MagicStarter\Enums\ReleaseItemType;
use FlutterSdk\MagicStarter\Enums\ReleaseStatus;
use FlutterSdk\MagicStarter\Models\AppRelease;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Default app release creation action.
 *
 * Also owns the rules and the normalisation {@see UpdateAppRelease} shares.
 */
class CreateAppRelease implements CreatesAppReleases
{
    /**
     * Validate and create an app release.
     *
     * @param  Authenticatable  $user  The staff user creating the release.
     * @param  array<string, mixed>  $input  The release data.
     * @return Model The created release.
     *
     * @throws ValidationException
     */
    public function create(Authenticatable $user, array $input): Model
    {
        return AppRelease::query()->create($this->attributes($input));
    }

    /**
     * Validate the input and shape it into the attributes a release stores.
     *
     * Item types and the status may arrive as strings or as enum instances, because
     * a panel select hands over the instance; `Rule::enum` accepts both, where
     * `string` and `in:` would not.
     *
     * @param  array<string, mixed>  $input  The release data.
     * @param  Model|null  $ignoring  The release being updated, so its own version is not a duplicate.
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function attributes(array $input, ?Model $ignoring = null): array
    {
        // 1. Validate against the default locale (required) and the other supported ones (optional).
        $defaultLocale = (string) config('magic-starter.defaults.locale');
        $textRules = ['items.*.text.' . $defaultLocale => ['required', 'string', 'max:500']];

        foreach ((array) config('magic-starter.supported_locales') as $locale) {
            $textRules['items.*.text.' . $locale] ??= ['nullable', 'string', 'max:500'];
        }

        $validated = Validator::make($input, [
            'version' => [
                'required',
                'string',
                'max:32',
                'regex:/^\d+\.\d+\.\d+$/',
                Rule::unique(AppRelease::class, 'version')->ignore($ignoring?->getKey()),
            ],
            'status' => ['required', Rule::enum(ReleaseStatus::class)],
            'published_at' => ['nullable', 'date', 'before_or_equal:now'],
            // PostgreSQL stores an unsignedInteger as a signed integer.
            'ios_build' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'android_build' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.type' => ['required', Rule::enum(ReleaseItemType::class)],
            ...$textRules,
        ])->validate();

        // 2. Store the backed value of each type and drop the locales left blank.
        $items = array_map(fn (array $item): array => [
            'type' => $item['type'] instanceof ReleaseItemType ? $item['type']->value : $item['type'],
            'text' => array_filter($item['text'], fn (mixed $text): bool => filled($text)),
        ], array_values($validated['items']));

        // 3. A release published without a date goes live now.
        $status = $validated['status'] instanceof ReleaseStatus
            ? $validated['status']
            : ReleaseStatus::from($validated['status']);
        $publishedAt = Arr::get($validated, 'published_at');

        if ($status === ReleaseStatus::PUBLISHED && blank($publishedAt)) {
            $publishedAt = now();
        }

        return [
            'version' => $validated['version'],
            'status' => $status,
            'published_at' => $publishedAt,
            'ios_build' => Arr::get($validated, 'ios_build'),
            'android_build' => Arr::get($validated, 'android_build'),
            'items' => $items,
        ];
    }
}
