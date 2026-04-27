<?php

namespace App\Helpers;

use App\Repositories\MasterMenuRepository;

class MenuPermissionHelper
{
    public function __construct(
        protected MasterMenuRepository $masterMenuRepository,
    ) {
    }

    public function resolveMenuId(string $menuIdentifier): int|string|null
    {
        if (is_numeric($menuIdentifier)) {
            return $menuIdentifier;
        }

        return $this->masterMenuRepository->findByCode($menuIdentifier)?->getKey();
    }
}
