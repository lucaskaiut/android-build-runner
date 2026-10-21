<?php

namespace App\Http\Requests\Api\V1;

use App\Models\BuildProfile;
use App\Rules\ArtifactPattern;
use App\Rules\SafeRelativePath;
use App\Rules\SecretFilePath;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveBuildProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only administrators may edit executable build configuration.
        return $this->user()?->can('manage-platform') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var BuildProfile|null $profile */
        $profile = $this->route('profile');
        $projectId = $profile?->project_id ?? $this->route('project')?->id;
        $required = $profile === null ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'slug' => [
                $required, 'string', 'max:80',
                'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/',
                Rule::unique('build_profiles', 'slug')->where('project_id', $projectId)->ignore($profile?->id),
            ],
            'build_script' => [$required, 'string', 'max:100000', 'not_regex:/\0/'],
            'working_directory' => ['nullable', 'string', 'max:255', new SafeRelativePath],
            'timeout_seconds' => [
                'sometimes', 'integer', 'min:30',
                'max:'.(int) config('builds.max_timeout_seconds'),
            ],
            'artifact_patterns' => ['nullable', 'array', 'max:50'],
            'artifact_patterns.*' => ['string', 'max:255', new ArtifactPattern],
            'env' => ['nullable', 'array', 'max:50', function (string $attribute, mixed $value, \Closure $fail) {
                if (! is_array($value)) {
                    return;
                }

                foreach ($value as $key => $item) {
                    if (! is_string($key) || preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
                        $fail('As chaves de variáveis de ambiente devem usar MAIÚSCULAS, números e "_" (ex.: APP_VARIANT).');

                        return;
                    }

                    if (! is_string($item) || strlen($item) > 500) {
                        $fail('Cada variável de ambiente deve ser um texto de até 500 caracteres.');

                        return;
                    }
                }
            }],
            'parameters' => ['nullable', 'array', 'max:20'],
            'parameters.*.key' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', 'distinct'],
            'parameters.*.label' => ['nullable', 'string', 'max:100'],
            'parameters.*.required' => ['sometimes', 'boolean'],
            'parameters.*.default' => ['nullable', 'string', 'max:500'],
            'parameters.*.options' => ['nullable', 'array', 'max:50'],
            'parameters.*.options.*' => ['string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'auto_submit' => ['sometimes', 'boolean'],
            'submit_config' => ['nullable', 'array'],
            'submit_config.provider' => ['nullable', 'string', Rule::in(['google_play'])],
            'submit_config.package_name' => [
                'nullable', 'string', 'max:255',
                'regex:/^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+$/',
            ],
            'submit_config.track' => ['nullable', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_-]*$/'],
            'submit_config.service_account_file' => [
                'nullable', 'string', 'max:500', new SecretFilePath, 'regex:/\.json$/i',
            ],
            'submit_config.artifact_pattern' => ['nullable', 'string', 'max:255', new ArtifactPattern],
            'submit_config.release_status' => ['nullable', 'string', Rule::in(['completed', 'draft'])],
            'submit_config.release_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var BuildProfile|null $profile */
            $profile = $this->route('profile');

            $autoSubmit = $this->has('auto_submit')
                ? $this->boolean('auto_submit')
                : (bool) ($profile?->auto_submit ?? false);

            if (! $autoSubmit) {
                return;
            }

            $config = $this->input('submit_config');

            if (! is_array($config) || $config === []) {
                $config = $profile?->submit_config ?? [];
            }

            $required = [
                'provider' => 'Informe o provedor do submit.',
                'package_name' => 'Informe o package name do app.',
                'track' => 'Informe a track da loja.',
                'service_account_file' => 'Informe o arquivo JSON da service account.',
                'artifact_pattern' => 'Informe o padrão do AAB.',
                'release_status' => 'Informe o status do release.',
            ];

            foreach ($required as $field => $message) {
                $value = $config[$field] ?? null;

                if (! is_string($value) || trim($value) === '') {
                    $validator->errors()->add('submit_config.'.$field, $message);
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'build_script.required' => 'Informe o script de build deste perfil.',
            'slug.unique' => 'Já existe um perfil com este identificador neste projeto.',
        ];
    }
}
