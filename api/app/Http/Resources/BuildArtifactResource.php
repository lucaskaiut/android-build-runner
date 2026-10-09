<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\BuildArtifact */
class BuildArtifactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'build_id' => $this->build_id,
            'original_name' => $this->original_name,
            'relative_path' => $this->relative_path,
            'type' => $this->type->value,
            'size_bytes' => $this->size_bytes,
            'sha256' => $this->sha256,
            'download_path' => '/api/v1/artifacts/'.$this->id.'/download',
            'created_at' => $this->created_at,
        ];
    }
}
