<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reads the code of a sign-in handoff exchange.
 *
 * It carries no validation rules on purpose: a code that is missing, not a string or too long is
 * one more code that does not exist, and must be refused with the same `handoff_expired` answer
 * as an unknown one rather than a field error that tells a prober which shape was wrong.
 */
class ExchangeHandoffRequest extends FormRequest
{
    /**
     * The longest code the exchange will look up; real codes are 64 characters.
     */
    private const MAX_CODE_LENGTH = 255;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * The submitted code, or null when it cannot be a code at all.
     */
    public function code(): ?string
    {
        $code = $this->input('code');

        if (! is_string($code) || $code === '' || strlen($code) > self::MAX_CODE_LENGTH) {
            return null;
        }

        return $code;
    }
}
