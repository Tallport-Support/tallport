<?php

namespace App\Ai;

use Illuminate\Database\Eloquent\Model;

/**
 * A part of a document with its embedding.
 */
class DocumentChunk extends Model
{
    protected $table = 'aiassistant_document_chunks';

    protected $fillable = ['document_id', 'chunk_index', 'content', 'content_hash', 'token_count', 'embedding', 'embedding_model', 'metadata'];

    protected $casts = [
        'embedding' => 'array',
        'metadata'  => 'array',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
