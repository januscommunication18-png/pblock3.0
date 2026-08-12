<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/** One user's star on one View (Views §5.2). */
class ProjectViewFavorite extends Model
{
    use BelongsToTenant;

    protected $table = 'project_view_favorites';

    protected $fillable = ['tenant_id', 'project_view_id', 'user_id'];

    public function view(): BelongsTo
    {
        return $this->belongsTo(ProjectView::class, 'project_view_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
