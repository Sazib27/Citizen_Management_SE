<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaxPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'tax_assessment_id',
        'payer_id',
        'recorded_by',
        'amount',
        'currency',
        'method',
        'reference',
        'paid_at',
        'notes',
        'gateway_response',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'gateway_response' => 'array',
    ];

    public function assessment()
    {
        return $this->belongsTo(TaxAssessment::class, 'tax_assessment_id');
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'payer_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
