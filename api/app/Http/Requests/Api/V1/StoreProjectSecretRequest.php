<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\SecretEnvironmentKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectSecretRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-platform') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $projectId = $this->route('project')?->id;

        return [
            'key' => [
                'required', 'string', 'max:120',
                new SecretEnvironmentKey,
                Rule::unique('project_secrets', 'key')->where('project_id', $projectId),
            ],
            'value' => ['required', 'string', 'max:65535', 'not_regex:/\0/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.unique' => 'Já existe um segredo com esta chave neste projeto.',
        ];
    }
}
