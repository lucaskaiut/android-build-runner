<?php

namespace Database\Factories;

use App\Models\BuildArtifact;
use App\Models\Build;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BuildArtifact>
 */
class BuildArtifactFactory extends Factory
{
    protected $model = BuildArtifact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'app-release-'.Str::lower(Str::random(6)).'.apk';

        return [
            'build_id' => Build::factory(),
            'original_name' => $name,
            'stored_name' => 'artifacts/0/'.Str::ulid().'.apk',
            'type' => 'apk',
            'size_bytes' => fake()->numberBetween(1024, 1024 * 1024),
            'sha256' => hash('sha256', $name),
        ];
    }
}
