<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A visitor with no account writing in through the public form.
 *
 * The adopter's `magic-starter.support.public.extra_rules` (a captcha, a
 * blocklist) are resolved from the container and run beside the package's own
 * rules, so a rule class may take constructor dependencies.
 */
class StorePublicSupportConversationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
            ],
            'name' => [
                'nullable',
                'string',
                'max:255',
            ],
            'subject' => [
                'required',
                'string',
                'max:255',
            ],
            'body' => [
                'required',
                'string',
                'max:' . (int) config('magic-starter.support.message_max_length', 2000),
            ],
            'source' => [
                'sometimes',
                Rule::enum(SupportConversationSource::class)->only([
                    SupportConversationSource::PUBLIC_FORM,
                    SupportConversationSource::CONTACT_SALES,
                ]),
            ],
        ];

        /** @var array<string, list<class-string>> $extra */
        $extra = (array) config('magic-starter.support.public.extra_rules', []);

        foreach ($extra as $field => $classes) {
            foreach ($classes as $class) {
                $rules[$field][] = $this->container->make($class);
            }
        }

        return $rules;
    }
}
