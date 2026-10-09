<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Project;
use App\Rules\BranchName;
use App\Rules\BranchPattern;
use App\Rules\SafeRelativePath;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'slug' => [
                'sometimes', 'required', 'string', 'max:80',
                'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/',
                Rule::unique('projects', 'slug')->ignore($project?->id),
            ],
            'repo_url' => ['sometimes', 'required', 'string', 'max:2048', function (string $attribute, mixed $value, \Closure $fail) {
                if (! is_string($value) || Project::normalizeRepositoryUrl($value) === null) {
                    $fail('Informe uma URL Git válida (SSH ou HTTPS), por exemplo git@github.com:org/repo.git.');
                }
            }],
            'default_branch' => ['sometimes', 'required', 'string', 'max:255', new BranchName],
            'working_directory' => ['sometimes', 'required', 'string', 'max:255', new SafeRelativePath],
            'allowed_branches' => ['sometimes', 'nullable', 'array', 'max:50'],
            'allowed_branches.*' => ['string', 'max:255', new BranchPattern],
            'is_active' => ['sometimes', 'boolean'],
            'webhook_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
