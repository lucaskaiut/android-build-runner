<?php

namespace Database\Factories;

use App\Models\BuildProfile;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BuildProfile>
 */
class BuildProfileFactory extends Factory
{
    protected $model = BuildProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => 'Preview',
            'slug' => 'preview',
            'build_script' => 'Write-Output "hello from the build script"',
            'working_directory' => null,
            'timeout_seconds' => 3600,
            'artifact_patterns' => [],
            'env' => [],
            'parameters' => [],
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function withArtifactPatterns(array $patterns): static
    {
        return $this->state(fn () => ['artifact_patterns' => $patterns]);
    }
}
