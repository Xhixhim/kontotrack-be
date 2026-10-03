<?php

namespace App\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'uploaded_by', 'counterparty_id', 'category', 'document_date', 'notes', 'original_name', 'disk', 'path', 'mime_type', 'size_bytes', 'sha256', 'review_status', 'extracted_data', 'reviewed_at', 'reviewed_by'])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'document_date' => 'date', 'extracted_data' => 'array', 'reviewed_at' => 'datetime'];
    }

    /** @return BelongsTo<Counterparty, $this> */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
