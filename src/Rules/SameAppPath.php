<?php

namespace FlutterSdk\MagicStarter\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validation rule for a path inside the same app, the only place a handoff may send the browser.
 *
 * The value is handed back to an app that opens it after a sign-in, so anything
 * a browser could resolve to another origin, or to a path the caller did not
 * name, is refused. Length is the request's `max` rule, not this one.
 */
final class SameAppPath implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->isSameAppPath($value)) {
            $fail((string) __('magic-starter::auth.handoff.next'));
        }
    }

    private function isSameAppPath(string $path): bool
    {
        // 1. Exactly one leading slash. `//host` is protocol-relative, and a
        //    path that starts with a slash cannot carry a scheme.
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return false;
        }

        // 2. Browsers read a backslash as a slash, so `/\host` is `//host`.
        if (str_contains($path, '\\')) {
            return false;
        }

        // 3. Browsers strip tab and newline before parsing, so `/<tab>/host` is
        //    `//host` too. Any whitespace, separator or control character is
        //    refused; invalid UTF-8 fails the match and is refused with it.
        if (preg_match('/[\s\p{Z}\p{C}]/u', $path) !== 0) {
            return false;
        }

        // 4. A `..` segment walks out of the path that was named. Browsers decode
        //    `%2e` before they resolve dot segments, so the encoded form counts.
        $segments = explode('/', Str::before(Str::before($path, '?'), '#'));

        foreach ($segments as $segment) {
            if (str_replace('%2e', '.', strtolower($segment)) === '..') {
                return false;
            }
        }

        return true;
    }
}
