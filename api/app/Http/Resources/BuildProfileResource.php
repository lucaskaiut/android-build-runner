<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\BuildProfile */
class BuildProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'build_script' => $this->build_script,
            'working_directory' => $this->working_directory,
            'timeout_seconds' => $this->timeout_seconds,
            'artifact_patterns' => $this->artifact_patterns ?? [],
            'env' => $this->env ?? (object) [],
            'parameters' => $this->parameterDefinitions(),
            'is_active' => (bool) $this->is_active,
            'auto_submit' => (bool) $this->auto_submit,
            'submit_config' => $this->submit_config,
            'builds_count' => $this->whenCounted('builds'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
