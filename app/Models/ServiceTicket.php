<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceTicket extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'ticket_no', 'store_id', 'customer_id', 'technician_id', 'product_brand',
        'product_type', 'imei', 'complaint', 'condition_note', 'photos',
        'diagnosis', 'estimate_cost', 'approval_status', 'status', 'priority',
        'dp', 'total_cost', 'warranty_days', 'taken_at', 'estimated_done_at', 'booking_id',
    ];

    protected $casts = [
        'photos' => 'array',
        'estimate_cost' => 'decimal:2',
        'dp' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'taken_at' => 'datetime',
        'estimated_done_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function spareparts(): HasMany
    {
        return $this->hasMany(ServiceSparepart::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ServiceLog::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(TechnicianCommission::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }
}
