<?php

namespace App\Services;

use App\Enums\BuildSource;
use App\Enums\WebhookDeliveryStatus;
use App\Models\GithubWebhookDelivery;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class GithubWebhookService
{
    public function __construct(
        private readonly BuildService $builds,
    ) {}

    /**
     * Validates and processes a GitHub push webhook. Never throws for expected
     * validation failures - returns an HTTP status and a safe JSON body.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(string $projectSlug, Request $request): array
    {
        $event = (string) $request->header('X-GitHub-Event', '');
        $deliveryId = trim((string) $request->header('X-GitHub-Delivery', ''));
        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $rawBody = $request->getContent();

        /** @var Project|null $project */
        $project = Project::where('slug', $projectSlug)->first();

        if ($project === null) {
            $this->recordStandalone(null, $deliveryId, $event, null, null, WebhookDeliveryStatus::Rejected, 'Projeto não encontrado.');

            return $this->response(404, 'rejected', 'Projeto não encontrado.');
        }

        if ($project->webhook_secret === null || $project->webhook_secret === '') {
            $this->recordStandalone($project, $deliveryId, $event, null, null, WebhookDeliveryStatus::Error, 'Segredo do webhook não configurado.');

            return $this->response(503, 'error', 'Webhook não configurado para este projeto.');
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $project->webhook_secret);

        if ($signature === '' || ! hash_equals($expected, $signature)) {
            // Registered without the delivery id so a legitimate delivery with
            // the same id can still be processed later.
            $this->recordStandalone($project, null, $event, null, null, WebhookDeliveryStatus::Rejected, 'Assinatura inválida.');
            Log::warning('github.webhook.invalid_signature', ['project_id' => $project->id]);

            return $this->response(403, 'rejected', 'Assinatura inválida.');
        }

        if ($deliveryId === '') {
            return $this->response(400, 'rejected', 'Cabeçalho X-GitHub-Delivery ausente.');
        }

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            $this->recordStandalone($project, $deliveryId, $event, null, null, WebhookDeliveryStatus::Rejected, 'Payload inválido.');

            return $this->response(400, 'rejected', 'Payload inválido.');
        }

        // Idempotency: the unique index on delivery_id guarantees a single
        // build per GitHub delivery even under concurrent redeliveries.
        $delivery = new GithubWebhookDelivery([
            'project_id' => $project->id,
            'delivery_id' => $deliveryId,
            'event' => $event,
            'status' => WebhookDeliveryStatus::Error,
            'message' => 'Processando.',
        ]);

        try {
            $delivery->save();
        } catch (QueryException $exception) {
            if ($this->isDuplicate($exception)) {
                $existing = GithubWebhookDelivery::where('delivery_id', $deliveryId)->first();

                // Record the redelivery for auditing without processing it again.
                $existing?->increment('redelivery_count');
                $existing?->refresh();

                return $this->response(200, 'duplicate', 'Entrega já processada.', [
                    'delivery_id' => $deliveryId,
                    'build_id' => $existing?->build_id,
                    'redelivery_count' => $existing?->redelivery_count,
                ]);
            }

            throw $exception;
        }

        try {
            return $this->processPush($project, $delivery, $payload, $event);
        } catch (Throwable $exception) {
            $delivery->forceFill([
                'status' => WebhookDeliveryStatus::Error,
                'message' => 'Erro inesperado ao processar o evento.',
            ])->save();

            Log::error('github.webhook.error', [
                'project_id' => $project->id,
                'delivery_id' => $deliveryId,
                'exception' => $exception->getMessage(),
            ]);

            return $this->response(500, 'error', 'Erro inesperado ao processar o evento.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    private function processPush(Project $project, GithubWebhookDelivery $delivery, array $payload, string $event): array
    {
        $ref = (string) data_get($payload, 'ref', '');
        $sha = mb_strtolower((string) (data_get($payload, 'after') ?: data_get($payload, 'head_commit.id', '')));

        $delivery->ref = $ref !== '' ? mb_substr($ref, 0, 255) : null;
        $delivery->sha = preg_match('/^[0-9a-f]{40}$/', $sha) === 1 ? $sha : null;

        if ($event !== 'push') {
            return $this->finish($delivery, WebhookDeliveryStatus::Ignored, "Evento '{$event}' ignorado.", 202, 'ignored');
        }

        if (! $project->is_active || ! $project->webhook_enabled) {
            return $this->finish($delivery, WebhookDeliveryStatus::Ignored, 'Automação desativada para este projeto.', 202, 'ignored');
        }

        $repositoryUrl = (string) (data_get($payload, 'repository.ssh_url') ?? data_get($payload, 'repository.clone_url', ''));

        if ($repositoryUrl === '' || ! $project->matchesRepositoryUrl($repositoryUrl)) {
            return $this->finish($delivery, WebhookDeliveryStatus::Rejected, 'O repositório do evento não corresponde ao projeto.', 403, 'rejected');
        }

        if (! str_starts_with($ref, 'refs/heads/')) {
            return $this->finish($delivery, WebhookDeliveryStatus::Ignored, 'O evento não é um push de branch.', 202, 'ignored');
        }

        $branch = mb_substr($ref, mb_strlen('refs/heads/'));

        if (preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            return $this->finish($delivery, WebhookDeliveryStatus::Rejected, 'SHA do commit ausente ou inválido no payload.', 422, 'rejected');
        }

        $trigger = $project->findBranchTrigger($branch);

        if ($trigger === null) {
            return $this->finish($delivery, WebhookDeliveryStatus::Ignored, "Branch \"{$branch}\" não está monitorada.", 202, 'ignored');
        }

        $profile = $trigger->profile;

        if ($profile === null || (int) $profile->project_id !== (int) $project->id || ! $profile->is_active) {
            return $this->finish($delivery, WebhookDeliveryStatus::Ignored, 'O perfil associado à branch está inativo.', 202, 'ignored');
        }

        try {
            $build = $this->builds->create(
                $project,
                $profile,
                ['branch' => $branch, 'commit_sha' => $sha],
                BuildSource::Webhook,
                null,
                $delivery,
                requireActiveProject: false,
            );
        } catch (\Illuminate\Validation\ValidationException $exception) {
            return $this->finish(
                $delivery,
                WebhookDeliveryStatus::Rejected,
                mb_substr(collect($exception->errors())->flatten()->first() ?? 'Solicitação rejeitada.', 0, 490),
                422,
                'rejected',
            );
        }

        $delivery->forceFill([
            'build_id' => $build->id,
        ])->save();

        return $this->finish(
            $delivery,
            WebhookDeliveryStatus::Accepted,
            "Build #{$build->id} criada para a branch \"{$branch}\".",
            202,
            'accepted',
            ['build_id' => $build->id],
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{status: int, body: array<string, mixed>}
     */
    private function finish(
        GithubWebhookDelivery $delivery,
        WebhookDeliveryStatus $status,
        string $message,
        int $httpStatus,
        string $bodyStatus,
        array $extra = [],
    ): array {
        $delivery->forceFill([
            'status' => $status,
            'message' => mb_substr($message, 0, 490),
        ])->save();

        return $this->response($httpStatus, $bodyStatus, $message, array_merge([
            'delivery_id' => $delivery->delivery_id,
        ], $extra));
    }

    private function recordStandalone(
        ?Project $project,
        ?string $deliveryId,
        string $event,
        ?string $ref,
        ?string $sha,
        WebhookDeliveryStatus $status,
        string $message,
    ): void {
        GithubWebhookDelivery::create([
            'project_id' => $project?->id,
            'delivery_id' => $deliveryId !== '' ? $deliveryId : null,
            'event' => $event !== '' ? $event : null,
            'ref' => $ref,
            'sha' => $sha,
            'status' => $status,
            'message' => mb_substr($message, 0, 490),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{status: int, body: array<string, mixed>}
     */
    private function response(int $status, string $result, string $message, array $extra = []): array
    {
        return [
            'status' => $status,
            'body' => array_merge(['status' => $result, 'message' => $message], $extra),
        ];
    }

    private function isDuplicate(QueryException $exception): bool
    {
        return $exception->getCode() === '23000' || str_contains($exception->getMessage(), 'Duplicate entry');
    }
}
