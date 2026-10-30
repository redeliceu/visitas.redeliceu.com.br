<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadStatusHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'lead_id',
        'from_status_id',
        'to_status_id',
        'changed_by',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<LeadStatus, $this> */
    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'from_status_id');
    }

    /** @return BelongsTo<LeadStatus, $this> */
    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'to_status_id');
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
