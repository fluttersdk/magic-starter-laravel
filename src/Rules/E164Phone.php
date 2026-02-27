<?php

namespace FlutterSdk\MagicStarter\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validation rule for E.164 international phone numbers.
 *
 * Pattern: +[country_code][subscriber_number]
 * Max length: 15 digits (excluding plus)
 */
final class E164Phone implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  string  $attribute  The name of the attribute being validated.
     * @param  mixed  $value  The value of the attribute being validated.
     * @param  Closure(string): PotentiallyTranslatedString  $fail  The failure callback.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 1. E.164 pattern: Starts with '+', followed by 1-15 digits.
        // 2. The first digit after '+' cannot be '0'.
        if (! is_string($value) || ! preg_match('/^\+[1-9]\d{0,14}$/', $value)) {
            $fail('The :attribute must be a valid E.164 international phone number (e.g., +14155552671).');
        }
    }
}
