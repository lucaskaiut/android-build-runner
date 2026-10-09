<?php

namespace Database\Factories;

use App\Enums\RunnerStatus;
use App\Models\Runner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Runner>
 */
class RunnerFactory extends Factory
{
    protected $model = Runner::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'runner-'.Str::lower(Str::random(8)),
            'software_version' => '1.0.0',
            'protocol_version' => 1,
            'status' => RunnerStatus::Offline,
            'last_heartbeat_at' => null,
            'revoked_at' => null,
        ];
    }

    public function online(): static
    {
        return $this->state(fn () => [
            'status' => RunnerStatus::Online,
            'last_heartbeat_at' => now(),
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => [
            'status' => RunnerStatus::Disabled,
            'revoked_at' => now(),
        ]);
    }

    public function withToken(): static
    {
        return $this->afterCreating(function (Runner $runner): void {
            $runner->createToken('runner', ['runner']);
        });
    }
}
