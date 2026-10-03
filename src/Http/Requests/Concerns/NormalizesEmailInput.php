<?php

namespace FlutterSdk\MagicStarter\Http\Requests\Concerns;

use Illuminate\Support\Str;

/**
 * Lower-cases the `email` input before validation, so `unique` and every
 * lookup compare normalized values.
 *
 * A non-string `email` is left alone for the rules to refuse.
 *
 * @mixin \Illuminate\Foundation\Http\FormRequest
 */
trait NormalizesEmailInput
{
    /**
     * Lower-case the email before the rules run.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge([
                'email' => Str::lower($this->input('email')),
            ]);
        }
    }
}
