<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The assistant's knowledge base - the "semantic index" half of the RAG
 * pipeline. One row per document: a piece of policy prose, or a database row
 * rendered as a sentence a person would actually write.
 *
 * Visibility is a column, not a convention. `visible_to_user_id` pins a
 * document to exactly one account (a client's booking, an owner's payout), and
 * `visible_to_role` covers the shared material. Retrieval filters on these
 * BEFORE similarity is computed, so another actor's document is never a
 * low-ranked result - it is not a candidate at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rag_documents', function (Blueprint $table) {
            $table->id();

            // Which builder produced this, and the row it was built from.
            // Together they are the document's identity: re-indexing upserts on
            // this pair rather than piling up duplicates.
            $table->string('source_type');
            $table->string('source_id');

            $table->string('title');
            $table->text('content');

            // Null = not tied to one account. Non-null = only this account.
            $table->foreignId('visible_to_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            // 'all', 'client' or 'owner' - what a shared document is scoped to.
            $table->string('visible_to_role')->default('all');

            // The vector, plus enough about how it was made to know when it can
            // no longer be compared: vectors from two different models sit in
            // unrelated spaces, and silently cosine-ing across them would return
            // confident nonsense.
            $table->json('embedding')->nullable();
            $table->string('embedding_model')->nullable();
            $table->unsignedSmallInteger('dimensions')->nullable();

            // Skips re-embedding a document whose text has not moved, which is
            // what makes an incremental re-index cheap.
            $table->string('content_hash');
            $table->timestamp('indexed_at')->nullable();

            $table->timestamps();

            $table->unique(['source_type', 'source_id']);
            // The shape of every retrieval query: narrow to what this user may
            // see, then rank what is left.
            $table->index(['visible_to_user_id', 'visible_to_role']);
            $table->index('source_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_documents');
    }
};
