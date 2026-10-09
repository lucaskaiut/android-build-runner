<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\SecretFilePath;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectSecretFileRequest extends FormRequest
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
            'path' => [
                'required', 'string', 'max:500',
                new SecretFilePath,
                Rule::unique('project_secret_files', 'path')->where('project_id', $projectId),
            ],
            'file' => ['required', 'file', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'path.unique' => 'Já existe um arquivo secreto neste caminho para o projeto.',
            'file.max' => 'O arquivo secreto deve ter no máximo 2 MB.',
        ];
    }
}
