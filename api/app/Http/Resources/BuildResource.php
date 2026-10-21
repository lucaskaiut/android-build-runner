<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Build */
class BuildResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'submit_status' => $this->submit_status?->value,
            'submit_status_label' => $this->submit_status?->label(),
            'submit_message' => $this->submit_message,
            'submit_version_code' => $this->submit_version_code,
            'submitted_at' => $this->submitted_at,
            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            'branch' => $this->branch,
            'commit_sha' => $this->commit_sha,
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project->id,
                'name' => $this->project->name,
                'slug' => $this->project->slug,
            ]),
            'profile' => $this->whenLoaded('profile', fn () => [
                'id' => $this->profile->id,
                'name' => $this->profile->name,
                'slug' => $this->profile->slug,
            ]),
            'runner' => $this->whenLoaded('runner', fn () => $this->runner === null ? null : [
                'id' => $this->runner->id,
                'name' => $this->runner->name,
            ]),
            'parameters' => $this->parameters,
            'queued_at' => $this->queued_at,
            'claimed_at' => $this->claimed_at,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'duration_ms' => $this->computedDurationMs(),
            'exit_code' => $this->exit_code,
            'error_message' => $this->error_message,
            'cancel_requested' => $this->isCancelRequested(),
            'cancel_requested_at' => $this->cancel_requested_at,
            'webhook_delivery_id' => $this->webhook_delivery_id,
            'artifacts_count' => $this->whenCounted('artifacts'),
            'artifacts' => BuildArtifactResource::collection($this->whenLoaded('artifacts')),
            'created_at' => $this->created_at,
        ];
    }
}
