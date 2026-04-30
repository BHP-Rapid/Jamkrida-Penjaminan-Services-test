<?php

namespace App\Repositories;

use App\Models\TenantMitra;

class KontraBankGaransiRepository
{
    public function getTenantMitraData($mitra_id)
    {
        return TenantMitra::where('mitra_id', $mitra_id)
            ->select('mitra_id', 'alias', 'tenant_id')
            ->first();
    }
}
