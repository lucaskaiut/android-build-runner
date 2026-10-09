<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ProjectBranchTrigger */
class ProjectBranchTriggerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'build_profile_id' => $this->build_profile_id,
            'branch_pattern' => $this->branch_pattern,
            'is_active' => (bool) $this->is_active,
            'profile' => $this->whenLoaded('profile', fn () => $this->profile === null ? null : [
                'id' => $this->profile->id,
                'name' => $this->profile->name,
                'slug' => $this->profile->slug,
                'is_active' => (bool) $this->profile->is_active,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
