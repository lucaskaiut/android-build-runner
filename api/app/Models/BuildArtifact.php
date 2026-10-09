<?php

namespace App\Models;

use App\Enums\ArtifactType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'build_id',
    'original_name',
    'relative_path',
    'stored_name',
    'type',
    'size_bytes',
    'sha256',
])]
class BuildArtifact extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ArtifactType::class,
            'size_bytes' => 'integer',
        ];
    }

    public function build(): BelongsTo
    {
        return $this->belongsTo(Build::class);
    }
}
