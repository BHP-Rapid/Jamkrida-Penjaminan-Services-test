<?php

namespace App\Services\KBGServices;

use Illuminate\Validation\ValidationException;

class KBGValidate
{
    public static function checkDuplicateLampiran(array $lampiran_list)
    {
        if(!empty($lampiran_list)) {
            $idMap = array_map(function ($item) {
                return $item['lampiran_id'];
            }, $lampiran_list);
            if(count(array_unique($idMap)) != count($idMap)) {
                throw ValidationException::withMessages([
                    'data.lampiran' => [
                        'Duplicate lampiran id.'
                    ]
                ]);
            }
            return true;
        }
        return false;
    }
}
