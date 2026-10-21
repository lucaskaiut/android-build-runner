<?php

namespace App\Services;

use App\Enums\BuildStatus;
use App\Enums\RunnerStatus;
use App\Enums\SubmitStatus;
use App\Exceptions\BuildConflictException;
use App\Models\Build;
use App\Models\Runner;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BuildLifecycleService
{
    /**
     * Hard timeout grace period on top of the profile timeout. Lets the API
     * fail builds whose runner is alive but never enforces its own timeout.
     */
    private const TIMEOUT_GRACE_SECONDS = 120;

    /**
     * Extra budget for the automatic Google Play upload/commit. The runner
     * also sends heartbeats during this step, but the absolute profile
     * timeout must not expire while the AAB is being uploaded.
     */
    private const SUBMIT_GRACE_SECONDS = 900;

    public function __construct(
        private readonly LogIngestService $logs,
        private readonly AuditLogger $audit,
        private readonly ArtifactService $artifacts,
    ) {}

    /**
     * Marks a claimed build as running and records the resolved commit SHA.
     */
    public function start(Build $build, Runner $runner, ?string $resolvedSha): Build
    {
        $this->assertOwnedBy($build, $runner);

        if ($build->status->isTerminal()) {
            throw BuildConflictException::invalidState(
                $build->status,
                'A build já foi finalizada e não pode ser iniciada.',
            );
        }

        $resolvedSha = $this->normalizeSha($resolvedSha);

        if ($resolvedSha !== null && $build->commit_sha !== null && $build->commit_sha !== $resolvedSha) {
            throw new BuildConflictException(
                'O commit resolvido pelo runner não corresponde ao commit solicitado na build.',
                ['requested_sha' => $build->commit_sha, 'resolved_sha' => $resolvedSha],
            );
        }

        return DB::transaction(function () use ($build, $runner, $resolvedSha): Build {
            $build->refresh();

            if ($build->status === BuildStatus::Running) {
                // Idempotent retry from the same runner.
                $this->renewLease($build, $runner);

                return $build->refresh();
            }

            if (! $build->status->canTransitionTo(BuildStatus::Running)) {
                throw BuildConflictException::invalidState(
                    $build->status,
                    'A build não está em um estado que permita iniciar a execução.',
                );
            }

            $build->forceFill([
                'status' => BuildStatus::Running,
                'started_at' => now(),
                'commit_sha' => $build->commit_sha ?? $resolvedSha,
                'last_heartbeat_at' => now(),
                'lease_expires_at' => now()->addSeconds((int) config('builds.lease_seconds')),
            ])->save();

            $runner->forceFill([
                'status' => RunnerStatus::Online,
                'last_heartbeat_at' => now(),
                'current_build_id' => $build->id,
            ])->save();

            $this->logs->appendSystem($build, sprintf(
                'Execução iniciada no commit %s.',
                $build->commit_sha !== null ? substr($build->commit_sha, 0, 12) : '(desconhecido)',
            ));

            return $build->refresh();
        });
    }

    /**
     * Renews the lease of a running build. Also returns the control state so
     * the runner can react to cancellation requests.
     */
    public function heartbeat(Build $build, Runner $runner): Build
    {
        $this->assertOwnedBy($build, $runner);

        if ($build->status->isActive()) {
            $this->renewLease($build, $runner);
        }

        return $build->refresh();
    }

    /**
     * Finalizes a build. The API never trusts the requested status blindly:
     * a success claim without a valid exit code or without the expected
     * artifacts is downgraded to a failure.
     */
    public function complete(
        Build $build,
        Runner $runner,
        BuildStatus $target,
        ?int $exitCode,
        ?string $errorMessage,
        ?SubmitStatus $submitStatus = null,
        ?string $submitMessage = null,
        ?string $submitVersionCode = null,
    ): Build {
        $this->assertOwnedBy($build, $runner);
        $build->loadMissing('profile');

        if (! in_array($target, [BuildStatus::Success, BuildStatus::Failed, BuildStatus::Cancelled], true)) {
            throw new BuildConflictException('Estado final inválido para conclusão.', [
                'target_status' => $target->value,
            ]);
        }

        if ($build->status->isTerminal()) {
            if ($build->status === $target) {
                return $build; // Idempotent retry.
            }

            throw BuildConflictException::invalidState(
                $build->status,
                'A build já foi finalizada como "'.$build->status->label().'".',
            );
        }

        if ($target === BuildStatus::Cancelled && ! $build->isCancelRequested()) {
            throw new BuildConflictException(
                'Cancelamento não solicitado para esta build.',
                ['current_status' => $build->status->value],
            );
        }

        if ($target === BuildStatus::Success) {
            if ($exitCode === null) {
                $target = BuildStatus::Failed;
                $errorMessage = 'O runner não informou o código de saída da execução.';
            } elseif ($exitCode !== 0) {
                $target = BuildStatus::Failed;
                $errorMessage = sprintf('O script de build terminou com código de saída %d.', $exitCode);
            } elseif (! $this->artifacts->hasExpectedArtifacts($build)) {
                $target = BuildStatus::Failed;
                $errorMessage = 'Artefato esperado não foi encontrado para este perfil.';
            }
        }

        $autoSubmit = (bool) ($build->profile?->auto_submit ?? false);
        $resolvedSubmitStatus = null;
        $resolvedSubmitMessage = $submitMessage;
        $resolvedSubmitVersionCode = $submitVersionCode;

        if ($autoSubmit) {
            if ($submitStatus !== null) {
                // The runner may report a submit result even when the API
                // downgrades the build (e.g. an artifact upload failure).
                $resolvedSubmitStatus = $submitStatus;
                $resolvedSubmitMessage = $submitMessage;
            } elseif ($target === BuildStatus::Success) {
                $resolvedSubmitStatus = SubmitStatus::Failed;
                $resolvedSubmitMessage = 'O runner não reportou o resultado do submit automático.';
            } else {
                $resolvedSubmitStatus = SubmitStatus::Skipped;
                $resolvedSubmitMessage = $submitMessage
                    ?: 'Build não concluída com sucesso; submit automático não executado.';
                $resolvedSubmitVersionCode = null;
            }
        } else {
            $resolvedSubmitStatus = null;
            $resolvedSubmitMessage = null;
            $resolvedSubmitVersionCode = null;
        }

        if ($resolvedSubmitStatus === SubmitStatus::Skipped) {
            $resolvedSubmitVersionCode = null;
        }

        $submittedAt = $resolvedSubmitStatus !== null && $resolvedSubmitStatus->isTerminal()
            ? now()
            : null;

        return DB::transaction(function () use (
            $build,
            $runner,
            $target,
            $exitCode,
            $errorMessage,
            $resolvedSubmitStatus,
            $resolvedSubmitMessage,
            $resolvedSubmitVersionCode,
            $submittedAt,
        ): Build {
            $build->refresh();

            if ($build->status->isTerminal()) {
                // A concurrent call finalized the build already.
                if ($build->status === $target) {
                    return $build;
                }

                throw BuildConflictException::invalidState(
                    $build->status,
                    'A build já foi finalizada como "'.$build->status->label().'".',
                );
            }

            if (! $build->status->canTransitionTo($target)) {
                throw BuildConflictException::invalidState(
                    $build->status,
                    'A build não está em um estado que permita concluí-la como "'.$target->label().'".',
                );
            }

            $finishedAt = now();
            $startedAt = $build->started_at ?? $build->claimed_at ?? $build->queued_at;

            $build->forceFill([
                'status' => $target,
                'submit_status' => $resolvedSubmitStatus,
                'submit_message' => $resolvedSubmitMessage,
                'submit_version_code' => $resolvedSubmitVersionCode,
                'submitted_at' => $submittedAt,
                'exit_code' => $exitCode,
                'error_message' => $target === BuildStatus::Success ? null : $errorMessage,
                'finished_at' => $finishedAt,
                'duration_ms' => $startedAt !== null
                    ? (int) $startedAt->diffInMilliseconds($finishedAt, true)
                    : null,
                'lease_expires_at' => null,
                'last_heartbeat_at' => $finishedAt,
            ])->save();

            if ((int) $runner->current_build_id === (int) $build->id) {
                $runner->forceFill([
                    'current_build_id' => null,
                    'last_heartbeat_at' => $finishedAt,
                ])->save();
            }

            $summary = match ($target) {
                BuildStatus::Success => sprintf('Build concluída com sucesso (exit 0) em %s.', $this->humanDuration($build->duration_ms)),
                BuildStatus::Cancelled => 'Build cancelada.',
                default => 'Build falhou'.($errorMessage !== null ? ': '.$errorMessage : '.'),
            };

            $this->logs->appendSystem($build, $summary);

            if ($resolvedSubmitStatus !== null) {
                $submitSummary = match ($resolvedSubmitStatus) {
                    SubmitStatus::Success => 'Submit para a loja concluído'
                        .($resolvedSubmitVersionCode !== null ? ' (versionCode '.$resolvedSubmitVersionCode.')' : '')
                        .'.',
                    SubmitStatus::Failed => 'Submit para a loja falhou'
                        .($resolvedSubmitMessage !== null ? ': '.$resolvedSubmitMessage : '.'),
                    SubmitStatus::Skipped => 'Submit automático não executado'
                        .($resolvedSubmitMessage !== null ? ': '.$resolvedSubmitMessage : '.'),
                    SubmitStatus::Pending => 'Submit automático pendente.',
                };

                $this->logs->appendSystem($build, $submitSummary);
            }

            return $build->refresh();
        });
    }

    /**
     * Requests cancellation. Queued builds are cancelled immediately; running
     * builds receive a pending cancellation flag that the runner observes.
     */
    public function cancel(Build $build, ?User $actor): Build
    {
        if ($build->status->isTerminal()) {
            throw BuildConflictException::invalidState(
                $build->status,
                'A build já foi finalizada e não pode ser cancelada.',
            );
        }

        if ($build->status === BuildStatus::Queued) {
            $build->loadMissing('profile');
            $fields = [
                'status' => BuildStatus::Cancelled,
                'cancel_requested_at' => now(),
                'finished_at' => now(),
                'duration_ms' => 0,
            ];

            if ($build->profile?->auto_submit) {
                $fields['submit_status'] = SubmitStatus::Skipped;
                $fields['submit_message'] = 'Build cancelada antes da execução; submit automático não executado.';
                $fields['submitted_at'] = now();
            }

            $build->forceFill($fields)->save();

            $this->logs->appendSystem($build, 'Build cancelada antes de ser reivindicada por um runner.');
        } else {
            $alreadyRequested = $build->cancel_requested_at !== null;

            if (! $alreadyRequested) {
                $build->forceFill([
                    'cancel_requested_at' => now(),
                ])->save();

                $this->logs->appendSystem($build, 'Cancelamento solicitado — aguardando o runner interromper a execução.');
            }
        }

        $this->audit->log($actor, 'build.cancel_requested', $build, [
            'status' => $build->status->value,
        ]);

        return $build->refresh();
    }

    /**
     * Reconciles builds with expired leases and offline runners. Builds are
     * failed, never silently requeued, because the API cannot prove that the
     * previous process stopped.
     *
     * @return array{expired: int, timed_out: int, runners_offline: int}
     */
    public function reap(): array
    {
        $expired = 0;
        $timedOut = 0;

        $stale = Build::withExpiredLease()->with(['runner', 'profile'])->get();

        foreach ($stale as $build) {
            if ($build->runner !== null && (int) $build->runner->current_build_id === (int) $build->id) {
                $build->runner->forceFill(['current_build_id' => null])->save();
            }

            $fields = [
                'status' => BuildStatus::Failed,
                'error_message' => 'Execução interrompida: o runner perdeu comunicação e a lease expirou.',
                'finished_at' => now(),
                'duration_ms' => $build->started_at !== null
                    ? (int) $build->started_at->diffInMilliseconds(now(), true)
                    : null,
                'lease_expires_at' => null,
            ];

            if ($build->profile?->auto_submit) {
                $fields['submit_status'] = SubmitStatus::Skipped;
                $fields['submit_message'] = 'Execução interrompida; submit automático não executado.';
                $fields['submitted_at'] = now();
            }

            $build->forceFill($fields)->save();

            $this->logs->appendSystem($build, 'Execução interrompida: lease expirada sem comunicação do runner.');
            $expired++;
        }

        // Safety net for runners that stay alive but never enforce the profile timeout.
        $overdue = Build::active()
            ->whereNotNull('claimed_at')
            ->with(['profile', 'runner'])
            ->get()
            ->filter(function (Build $build): bool {
                $submitGrace = $build->profile?->auto_submit ? self::SUBMIT_GRACE_SECONDS : 0;
                $deadline = ($build->started_at ?? $build->claimed_at)?->copy()
                    ->addSeconds(
                        ($build->profile?->timeout_seconds ?? (int) config('builds.default_timeout_seconds'))
                        + self::TIMEOUT_GRACE_SECONDS
                        + $submitGrace
                    );

                return $deadline !== null && $deadline->isPast();
            });

        foreach ($overdue as $build) {
            $fields = [
                'status' => BuildStatus::Failed,
                'error_message' => 'Tempo máximo de execução excedido (timeout do perfil).',
                'finished_at' => now(),
                'duration_ms' => $build->started_at !== null
                    ? (int) $build->started_at->diffInMilliseconds(now(), true)
                    : null,
                'lease_expires_at' => null,
                // Asked the runner to stop if it ever checks in again.
                'cancel_requested_at' => $build->cancel_requested_at ?? now(),
            ];

            if ($build->profile?->auto_submit) {
                $fields['submit_status'] = SubmitStatus::Skipped;
                $fields['submit_message'] = 'Tempo máximo excedido; submit automático não executado.';
                $fields['submitted_at'] = now();
            }

            $build->forceFill($fields)->save();

            if ($build->runner !== null && (int) $build->runner->current_build_id === (int) $build->id) {
                $build->runner->forceFill(['current_build_id' => null])->save();
            }

            $this->logs->appendSystem($build, 'Execução encerrada pela API: timeout máximo excedido.');
            $timedOut++;
        }

        $runnersOffline = Runner::query()
            ->where('status', RunnerStatus::Online->value)
            ->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '<', now()->subSeconds((int) config('builds.runner_offline_seconds')))
            ->update(['status' => RunnerStatus::Offline->value]);

        return [
            'expired' => $expired,
            'timed_out' => $timedOut,
            'runners_offline' => $runnersOffline,
        ];
    }

    private function assertOwnedBy(Build $build, Runner $runner): void
    {
        if ((int) $build->runner_id !== (int) $runner->id) {
            throw BuildConflictException::notOwnedByRunner();
        }
    }

    private function renewLease(Build $build, Runner $runner): void
    {
        $now = now();

        $build->forceFill([
            'last_heartbeat_at' => $now,
            'lease_expires_at' => $now->copy()->addSeconds((int) config('builds.lease_seconds')),
        ])->save();

        $runner->forceFill([
            'status' => RunnerStatus::Online,
            'last_heartbeat_at' => $now,
        ])->save();
    }

    private function normalizeSha(?string $sha): ?string
    {
        if ($sha === null) {
            return null;
        }

        $sha = mb_strtolower(trim($sha));

        if ($sha === '') {
            return null;
        }

        if (! preg_match('/^[0-9a-f]{40}$/', $sha)) {
            throw new BuildConflictException('SHA de commit inválido informado pelo runner.', ['sha' => $sha]);
        }

        return $sha;
    }

    private function humanDuration(?int $milliseconds): string
    {
        if ($milliseconds === null) {
            return 'tempo desconhecido';
        }

        $seconds = intdiv($milliseconds, 1000);

        if ($seconds < 60) {
            return $seconds.'s';
        }

        return intdiv($seconds, 60).'m '.($seconds % 60).'s';
    }
}
