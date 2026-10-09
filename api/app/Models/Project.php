<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'name',
    'slug',
    'repo_url',
    'default_branch',
    'working_directory',
    'allowed_branches',
    'is_active',
    'webhook_enabled',
    'webhook_secret',
])]
class Project extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allowed_branches' => 'array',
            'is_active' => 'boolean',
            'webhook_enabled' => 'boolean',
            'webhook_secret' => 'encrypted',
        ];
    }

    public function buildProfiles(): HasMany
    {
        return $this->hasMany(BuildProfile::class);
    }

    public function builds(): HasMany
    {
        return $this->hasMany(Build::class);
    }

    public function latestBuild(): HasOne
    {
        return $this->hasOne(Build::class)->latestOfMany();
    }

    public function branchTriggers(): HasMany
    {
        return $this->hasMany(ProjectBranchTrigger::class);
    }

    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(GithubWebhookDelivery::class);
    }

    public function hasBuilds(): bool
    {
        return $this->builds()->exists();
    }

    /**
     * Compares repository URLs ignoring transport differences:
     * git@github.com:org/repo.git, ssh://git@github.com/org/repo.git and
     * https://github.com/org/repo.git all normalize to "github.com/org/repo".
     */
    public static function normalizeRepositoryUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        $path = null;

        if (preg_match('#^[a-z0-9._-]+@([^:]+):(.+)$#i', $url, $matches)) {
            $path = $matches[1].'/'.$matches[2];
        } elseif (preg_match('#^(?:ssh|https?)://(?:[^@/]+@)?([^/]+)/(.+)$#i', $url, $matches)) {
            $path = $matches[1].'/'.$matches[2];
        }

        if ($path === null) {
            return null;
        }

        $path = preg_replace('/\.git$/', '', $path) ?? $path;
        $path = rtrim($path, '/');

        return mb_strtolower($path);
    }

    public function matchesRepositoryUrl(string $url): bool
    {
        $expected = self::normalizeRepositoryUrl($this->repo_url);
        $given = self::normalizeRepositoryUrl($url);

        return $expected !== null && $expected === $given;
    }

    public function findBranchTrigger(string $branch): ?ProjectBranchTrigger
    {
        return $this->branchTriggers()
            ->where('is_active', true)
            ->get()
            ->first(fn (ProjectBranchTrigger $trigger) => $trigger->matches($branch));
    }
}
