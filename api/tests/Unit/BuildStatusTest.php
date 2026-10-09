<?php

namespace Tests\Unit;

use App\Enums\BuildStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BuildStatusTest extends TestCase
{
    public function test_terminal_states_are_final(): void
    {
        $this->assertTrue(BuildStatus::Success->isTerminal());
        $this->assertTrue(BuildStatus::Failed->isTerminal());
        $this->assertTrue(BuildStatus::Cancelled->isTerminal());

        $this->assertEmpty(BuildStatus::Success->allowedTransitions());
        $this->assertEmpty(BuildStatus::Failed->allowedTransitions());
        $this->assertEmpty(BuildStatus::Cancelled->allowedTransitions());
    }

    public function test_valid_transitions(): void
    {
        $this->assertTrue(BuildStatus::Queued->canTransitionTo(BuildStatus::Preparing));
        $this->assertTrue(BuildStatus::Queued->canTransitionTo(BuildStatus::Cancelled));
        $this->assertTrue(BuildStatus::Preparing->canTransitionTo(BuildStatus::Running));
        $this->assertTrue(BuildStatus::Preparing->canTransitionTo(BuildStatus::Failed));
        $this->assertTrue(BuildStatus::Preparing->canTransitionTo(BuildStatus::Cancelled));
        $this->assertTrue(BuildStatus::Running->canTransitionTo(BuildStatus::Success));
        $this->assertTrue(BuildStatus::Running->canTransitionTo(BuildStatus::Failed));
        $this->assertTrue(BuildStatus::Running->canTransitionTo(BuildStatus::Cancelled));
    }

    #[DataProvider('invalidTransitions')]
    public function test_invalid_transitions(BuildStatus $from, BuildStatus $to): void
    {
        $this->assertFalse($from->canTransitionTo($to));
    }

    public static function invalidTransitions(): array
    {
        return [
            [BuildStatus::Queued, BuildStatus::Running],
            [BuildStatus::Queued, BuildStatus::Success],
            [BuildStatus::Queued, BuildStatus::Failed],
            [BuildStatus::Success, BuildStatus::Failed],
            [BuildStatus::Failed, BuildStatus::Success],
            [BuildStatus::Cancelled, BuildStatus::Running],
            [BuildStatus::Running, BuildStatus::Preparing],
        ];
    }

    public function test_cancellable_states(): void
    {
        $this->assertTrue(BuildStatus::Queued->canBeCancelled());
        $this->assertTrue(BuildStatus::Preparing->canBeCancelled());
        $this->assertTrue(BuildStatus::Running->canBeCancelled());
        $this->assertFalse(BuildStatus::Success->canBeCancelled());
        $this->assertFalse(BuildStatus::Failed->canBeCancelled());
        $this->assertFalse(BuildStatus::Cancelled->canBeCancelled());
    }
}
