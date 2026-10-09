<?php

namespace App\Services;

use App\Enums\BuildSource;
use App\Enums\BuildStatus;
use App\Models\Build;
use App\Models\BuildProfile;
use App\Models\GithubWebhookDelivery;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BuildService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly LogIngestService $logs,
    ) {}

    /**
     * Creates a queued build. The API only records intent here - the runner
     * performs the actual work after claiming the build.
     *
     * @param  array{branch?: string|null, commit_sha?: string|null, parameters?: array<string, string>|null}  $input
     */
    public function create(
        Project $project,
        BuildProfile $profile,
        array $input,
        BuildSource $source,
        ?User $actor = null,
        ?GithubWebhookDelivery $delivery = null,
        bool $requireActiveProject = true,
    ): Build {
        if ((int) $profile->project_id !== (int) $project->id) {
            throw ValidationException::withMessages([
                'build_profile_id' => 'O perfil informado não pertence a este projeto.',
            ]);
        }

        if (! $profile->is_active) {
            throw ValidationException::withMessages([
                'build_profile_id' => 'Este perfil de build está inativo.',
            ]);
        }

        if ($requireActiveProject && ! $project->is_active) {
            throw ValidationException::withMessages([
                'project_id' => 'Este projeto está inativo.',
            ]);
        }

        $commitSha = $this->normalizeCommitSha($input['commit_sha'] ?? null);
        $branch = $this->normalizeBranch($input['branch'] ?? null);

        if ($commitSha === null && $branch === null) {
            $branch = $project->default_branch;
        }

        if ($branch !== null) {
            $this->assertBranchAllowed($project, $branch);
        }

        $parameters = $this->resolveParameters($profile, $input['parameters'] ?? []);

        $build = new Build([
            'project_id' => $project->id,
            'build_profile_id' => $profile->id,
            'requested_by_user_id' => $actor?->id,
            'source' => $source,
            'branch' => $branch,
            'commit_sha' => $commitSha,
            'parameters' => $parameters === [] ? null : $parameters,
            'webhook_delivery_id' => $delivery?->id,
        ]);
        $build->status = BuildStatus::Queued;
        $build->queued_at = now();
        $build->save();

        $this->audit->log($actor, 'build.created', $build, [
            'source' => $source->value,
            'branch' => $branch,
            'commit_sha' => $commitSha,
        ]);

        $this->logs->appendSystem($build, sprintf(
            'Build #%d na fila — projeto "%s", perfil "%s" (%s).',
            $build->id,
            $project->name,
            $profile->name,
            $commitSha !== null ? 'commit '.substr($commitSha, 0, 12) : 'branch '.$branch,
        ));

        return $build;
    }

    private function normalizeCommitSha(?string $sha): ?string
    {
        if ($sha === null) {
            return null;
        }

        $sha = mb_strtolower(trim($sha));

        if ($sha === '') {
            return null;
        }

        if (! preg_match('/^[0-9a-f]{40}$/', $sha)) {
            throw ValidationException::withMessages([
                'commit_sha' => 'O commit deve ser um SHA de 40 caracteres hexadecimais.',
            ]);
        }

        return $sha;
    }

    private function normalizeBranch(?string $branch): ?string
    {
        if ($branch === null) {
            return null;
        }

        $branch = trim($branch);

        return $branch === '' ? null : $branch;
    }

    private function assertBranchAllowed(Project $project, string $branch): void
    {
        if (strlen($branch) > 255 || preg_match('/[\s~^:?*\[\\\\]/', $branch) === 1
            || str_contains($branch, '..') || str_starts_with($branch, '/') || str_ends_with($branch, '/')
            || str_contains($branch, '//')) {
            throw ValidationException::withMessages([
                'branch' => 'Nome de branch inválido.',
            ]);
        }

        $allowed = is_array($project->allowed_branches) ? $project->allowed_branches : [];

        if ($allowed === []) {
            return;
        }

        foreach ($allowed as $pattern) {
            if (Str::is((string) $pattern, $branch)) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'branch' => 'Esta branch não é permitida pela política do projeto.',
        ]);
    }

    /**
     * Validates user supplied parameters against the profile definition and
     * applies defaults. Unknown keys are rejected so arbitrary data can never
     * reach the build script through the webhook or the panel.
     *
     * @param  array<string, mixed>  $given
     * @return array<string, string>
     */
    public function resolveParameters(BuildProfile $profile, array $given): array
    {
        $definitions = $profile->parameterDefinitions();
        $known = array_map(fn (array $definition) => (string) ($definition['key'] ?? ''), $definitions);

        foreach (array_keys($given) as $key) {
            if (! in_array((string) $key, $known, true)) {
                throw ValidationException::withMessages([
                    'parameters.'.$key => "O parâmetro '{$key}' não é aceito por este perfil.",
                ]);
            }
        }

        $resolved = [];

        foreach ($definitions as $definition) {
            $key = (string) ($definition['key'] ?? '');

            if ($key === '') {
                continue;
            }

            if (array_key_exists($key, $given)) {
                $value = (string) $given[$key];
            } elseif (array_key_exists('default', $definition) && $definition['default'] !== null && $definition['default'] !== '') {
                $value = (string) $definition['default'];
            } elseif (! empty($definition['required'])) {
                throw ValidationException::withMessages([
                    'parameters.'.$key => "O parâmetro '{$key}' é obrigatório.",
                ]);
            } else {
                continue;
            }

            $options = $definition['options'] ?? null;

            if (is_array($options) && $options !== [] && ! in_array($value, array_map('strval', $options), true)) {
                throw ValidationException::withMessages([
                    'parameters.'.$key => "O valor informado para '{$key}' não é permitido.",
                ]);
            }

            if (strlen($value) > 500) {
                throw ValidationException::withMessages([
                    'parameters.'.$key => "O valor informado para '{$key}' é muito longo.",
                ]);
            }

            $resolved[$key] = $value;
        }

        return $resolved;
    }
}
