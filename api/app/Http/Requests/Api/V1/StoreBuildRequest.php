<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\BranchName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBuildRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $project = $this->route('project');

        return [
            'build_profile_id' => [
                'required', 'integer',
                Rule::exists('build_profiles', 'id')->where('project_id', $project?->id),
            ],
            'branch' => ['nullable', 'string', 'max:255', new BranchName],
            'commit_sha' => ['nullable', 'string', 'regex:/^[0-9a-fA-F]{40}$/'],
            'parameters' => ['nullable', 'array', 'max:20'],
            'parameters.*' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'build_profile_id.exists' => 'O perfil selecionado não pertence a este projeto.',
            'commit_sha.regex' => 'O commit deve ser um SHA de 40 caracteres hexadecimais.',
        ];
    }
}
