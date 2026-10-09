<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ProjectSecretFile */
class ProjectSecretFileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'path' => $this->path,
            'size' => (int) $this->size,
            'sha256' => $this->sha256,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
