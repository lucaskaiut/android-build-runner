<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\GithubWebhookDelivery */
class GithubWebhookDeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'delivery_id' => $this->delivery_id,
            'event' => $this->event,
            'ref' => $this->ref,
            'sha' => $this->sha,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'redelivery_count' => (int) $this->redelivery_count,
            'message' => $this->message,
            'build_id' => $this->build_id,
            'created_at' => $this->created_at,
        ];
    }
}
