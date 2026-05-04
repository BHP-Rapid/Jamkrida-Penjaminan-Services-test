<?php

namespace App\Services\KBGServices;

use Illuminate\Validation\ValidationException;

class KBGValidate
{
    public static function checkDuplicateLampiran(array $lampiran_list, string $lampiran_field_name = 'data.lampiran')
    {
        if(!empty($lampiran_list)) {
            $idMap = array_map(function ($item) {
                return $item['lampiran_id'];
            }, $lampiran_list);
            if(count(array_unique($idMap)) != count($idMap)) {
                throw ValidationException::withMessages([
                    $lampiran_field_name => [
                        'Duplicate lampiran id.'
                    ]
                ]);
            }
            return true;
        }
        return false;
    }
}
