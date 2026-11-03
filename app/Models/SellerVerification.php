<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerVerification extends Model
{
    protected $fillable = [
        'seller_id', 'legal_name', 'registered_address', 'tax_id_type', 'tax_id_last4',
        'business_doc_path', 'id_doc_path', 'address_proof_path', 'status',
        'rejection_reason', 'reviewed_by', 'submitted_at', 'reviewed_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function hasAllDocs(): bool
    {
        return $this->id_doc_path && $this->business_doc_path;
    }
}
