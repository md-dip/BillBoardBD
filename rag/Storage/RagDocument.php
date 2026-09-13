<?php

namespace Rag\Storage;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Rag\Ingestion\IngestionPipeline;
use Rag\Retrieval\RetrievalPipeline;

/**
 * One document in the assistant's knowledge base - this table is where "store
 * in a vector DB" from the pipeline diagram actually happens. There is no
 * separate vector database here: the app's own Postgres plays that role, with
 * the vector kept in the `embedding` JSON column. See config/rag.php for why
 * that is a size decision rather than a shortcut.
 *
 * @see IngestionPipeline   writes these
 * @see RetrievalPipeline   reads them
 */
#[Fillable([
    'source_type', 'source_id', 'title', 'content',
    'visible_to_user_id', 'visible_to_role',
    'embedding', 'embedding_model', 'dimensions', 'content_hash', 'indexed_at',
])]
class RagDocument extends Model
{
    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'indexed_at' => 'datetime',
        ];
    }

    public function visibleToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'visible_to_user_id');
    }

    /**
     * Everything this user is allowed to have retrieved: documents pinned to
     * them, plus shared material scoped to everyone or to their role.
     *
     * This is the security boundary of the whole feature. It is a query scope
     * rather than a prompt instruction on purpose - a WHERE clause cannot be
     * talked out of it.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('visible_to_user_id', $user->id)
                ->orWhere(function (Builder $shared) use ($user) {
                    $shared->whereNull('visible_to_user_id')
                        ->whereIn('visible_to_role', ['all', $user->role]);
                });
        });
    }

    /** Documents that carry a usable vector from the model currently in use. */
    public function scopeEmbeddedWith(Builder $query, string $model): Builder
    {
        return $query->whereNotNull('embedding')->where('embedding_model', $model);
    }
}
