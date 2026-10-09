<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['project_id', 'build_profile_id', 'branch_pattern', 'is_active'])]
class ProjectBranchTrigger extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(BuildProfile::class, 'build_profile_id');
    }

    /**
     * Exact branch names or simple "*" patterns such as "release/*".
     */
    public function matches(string $branch): bool
    {
        return Str::is($this->branch_pattern, $branch);
    }
}
