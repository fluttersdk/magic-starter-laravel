<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use FlutterSdk\MagicStarter\Rules\SameAppPath;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Validates the mint of a sign-in handoff code, which a browser on another origin calls.
 *
 * `next` is the path the app opens once the code is exchanged, and must stay inside the app.
 */
class IssueHandoffRequest extends FormRequest
{
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
        return [
            'next' => ['nullable', 'string', 'max:512', new SameAppPath],
        ];
    }

    /**
     * The validated `next`, or null when the caller named none.
     */
    public function next(): ?string
    {
        $next = $this->validated('next');

        return is_string($next) ? $next : null;
    }

    /**
     * Answer a refusal as JSON whatever the browser accepts.
     *
     * The default for a request that does not ask for JSON is a redirect back
     * with errors flashed to the session, and this call has no session and no
     * page to go back to.
     *
     * @throws ValidationException
     */
    protected function failedValidation(Validator $validator): void
    {
        $exception = new ValidationException($validator);
        $exception->response = new JsonResponse([
            'message' => $exception->getMessage(),
            'errors' => $exception->errors(),
        ], 422);

        throw $exception;
    }
}
