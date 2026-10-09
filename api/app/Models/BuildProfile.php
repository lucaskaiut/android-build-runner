<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'project_id',
    'name',
    'slug',
    'build_script',
    'working_directory',
    'timeout_seconds',
    'artifact_patterns',
    'env',
    'parameters',
    'is_active',
])]
class BuildProfile extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'artifact_patterns' => 'array',
            'env' => 'array',
            'parameters' => 'array',
            'is_active' => 'boolean',
            'timeout_seconds' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function builds(): HasMany
    {
        return $this->hasMany(Build::class);
    }

    /**
     * Working directory relative to the repository root. Falls back to the
     * project configuration when the profile does not override it.
     */
    public function effectiveWorkingDirectory(Project $project): string
    {
        $directory = trim((string) ($this->working_directory ?? ''));

        return $directory !== '' ? $directory : $project->working_directory;
    }

    /**
     * @return array<int, array{key: string, label?: string, required?: bool, default?: string|null, options?: array<int, string>}>
     */
    public function parameterDefinitions(): array
    {
        return is_array($this->parameters) ? $this->parameters : [];
    }
}
