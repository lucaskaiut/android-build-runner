<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Project */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'repo_url' => $this->repo_url,
            'default_branch' => $this->default_branch,
            'working_directory' => $this->working_directory,
            'allowed_branches' => $this->allowed_branches ?? [],
            'is_active' => (bool) $this->is_active,
            'webhook_enabled' => (bool) $this->webhook_enabled,
            'webhook_secret_configured' => $this->webhook_secret !== null && $this->webhook_secret !== '',
            'webhook_path' => '/api/webhooks/github/'.$this->slug,
            'profiles_count' => $this->whenCounted('buildProfiles'),
            'builds_count' => $this->whenCounted('builds'),
            'last_build' => new BuildResource($this->whenLoaded('latestBuild')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
