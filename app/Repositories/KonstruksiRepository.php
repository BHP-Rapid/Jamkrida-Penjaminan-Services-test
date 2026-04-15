<?php

namespace App\Repositories;

use Carbon\Carbon;

class KonstruksiRepository
{
    public function getNowJakarta(): Carbon
    {
        return Carbon::now('Asia/Jakarta');
    }
}
