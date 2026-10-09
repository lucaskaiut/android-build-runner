<?php

namespace App\Models;

use App\Enums\WebhookDeliveryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id',
    'delivery_id',
    'event',
    'ref',
    'sha',
    'status',
    'redelivery_count',
    'message',
    'build_id',
])]
class GithubWebhookDelivery extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WebhookDeliveryStatus::class,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function build(): BelongsTo
    {
        return $this->belongsTo(Build::class, 'build_id');
    }
}
