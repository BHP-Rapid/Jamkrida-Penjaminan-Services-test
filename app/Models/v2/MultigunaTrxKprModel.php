<?php

namespace App\Models\v2;

use App\Models\User;
use App\Models\v2\TrxDebiturKpr;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MultigunaTrxKprModel extends Model
{
    protected $table = 'multiguna_trx_kpr';
    protected $primaryKey = 'id_multiguna_kpr';

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
        'fee_base_number'      => 'integer',
        'fee_base_percentage'  => 'decimal:3',
        'created_at'           => 'datetime',
        'updated_at'           => 'datetime',
    ];

    public function debiturKpr(): HasMany
    {
        return $this->hasMany(
            TrxDebiturKprModel::class,
            'id_multiguna_kpr',
            'id_multiguna_kpr'
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