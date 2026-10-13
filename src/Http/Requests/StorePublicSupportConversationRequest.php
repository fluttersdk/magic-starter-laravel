<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use FlutterSdk\MagicStarter\Enums\SupportConversationSource;
use FlutterSdk\MagicStarter\Support\SupportRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A visitor with no account writing in through the public form.
 *
 * The adopter's `magic-starter.support.public.extra_rules` (a captcha, a
 * blocklist) are resolved from the container and run beside the package's own
 * rules, so a rule class may take constructor dependencies. The subject and
 * the topic are optional; the action derives a missing subject.
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
            'email' => SupportRules::email(),
            'name' => SupportRules::name(),
            ...SupportRules::conversation([
                SupportConversationSource::PUBLIC_FORM,
                SupportConversationSource::CONTACT_SALES,
            ]),
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
