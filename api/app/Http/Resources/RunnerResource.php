<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Runner */
class RunnerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $effective = $this->effectiveStatus();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $effective->value,
            'status_label' => $effective->label(),
            'software_version' => $this->software_version,
            'protocol_version' => $this->protocol_version,
            'os_info' => $this->os_info,
            'last_heartbeat_at' => $this->last_heartbeat_at,
            'current_build' => $this->current_build_id === null ? null : [
                'id' => $this->current_build_id,
                'status' => $this->relationLoaded('currentBuild') ? $this->currentBuild?->status->value : null,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
