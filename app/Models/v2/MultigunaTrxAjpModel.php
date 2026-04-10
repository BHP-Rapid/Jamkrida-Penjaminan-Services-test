<?php

namespace App\Models\v2;

use App\Models\AjpDebiturInvoiceHeader;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MultigunaTrxAjpModel extends Model
{
    protected $table = 'multiguna_trx_ajp';
    protected $primaryKey = 'id_multiguna_ajp';

    public $timestamps = true;

    protected $fillable = [
        'trx_no',
        'jenis_product',
        'jenis_product_description',
        'pks_number',
        'fee_base_number',
        'fee_base_percentage',
        'text_certified',
        'bank_name',
        'bank_code',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'fee_base_number' => 'integer',
        'fee_base_percentage' => 'decimal:3',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function debiturAjp(): HasMany
    {
        return $this->hasMany(
            TrxDebiturAjpModel::class,
            'id_multiguna_ajp',
            'id_multiguna_ajp'
        );
    }

    public function invoiceHeaders(): HasMany
    {
        return $this->hasMany(
            AjpDebiturInvoiceHeader::class,
            'debitur_trx_id',
            'id_multiguna_ajp'
        );
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'id');
    }
}
