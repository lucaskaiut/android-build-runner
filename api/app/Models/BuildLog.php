<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['build_id', 'batch_id', 'line_no', 'stream', 'message', 'created_at'])]
class BuildLog extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function build(): BelongsTo
    {
        return $this->belongsTo(Build::class);
    }
}
