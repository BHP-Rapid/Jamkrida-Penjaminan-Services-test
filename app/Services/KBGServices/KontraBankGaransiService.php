<?php

namespace App\Services\KBGServices;

use App\Exceptions\NotFoundException;
use App\Repositories\KontraBankGaransiRepository;
use Illuminate\Http\Request;

class KontraBaknGaransiService
{
    public function __construct(
        protected KontraBankGaransiRepository $repository
    ) {

    }

    public function kbgStore(Request $request, $user)
    {
        $mitraData = $this->getTenantDataOrFail($user->mitra_id);
        // full logic to be added
        return [
            'success' => true
        ];
    }

    private function getTenantDataOrFail($mitra_id)
    {
        $tenantData = $this->repository->getTenantMitraData($mitra_id);
        if(!$tenantData)
        {
            throw new NotFoundException('Tenant mitra data is not found.');
        }
        return $tenantData;
    }
}
