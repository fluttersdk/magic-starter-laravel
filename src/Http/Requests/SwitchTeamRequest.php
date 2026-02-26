<?php

namespace FlutterSdk\MagicStarter\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use FlutterSdk\MagicStarter\MagicStarter;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Gate;
class SwitchTeamRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to switch to the given team.
     */
    public function authorize(): bool
    {
        $teamModel = MagicStarter::teamModel();
        $team = $teamModel::find($this->input('team_id'));

        if (! $team) {
            return false;
        }

        return Gate::allows('switchTo', $team);
    }

    /**
     * Get the validation rules that apply to the team switch request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'team_id' => [
                'required',
                'uuid',
                Rule::exists(MagicStarter::teamModel(), 'id'),
            ],
        ];
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'team_id.exists' => 'The selected team does not exist.',
        ];
    }
}
