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

    public static function validateWithReturnManualPay(string $selected_items)
    {
        if(!json_validate($selected_items) || !is_array(json_decode($selected_items)))
        {
            return [
                'success' => false,
                'message' => 'Invalid selected item data.'
            ];
        }
        $parsedItems = json_decode($selected_items);
        $arrInvoiceNoTemp = collect($parsedItems)->pluck('invoice_number')->toArray();
        if(count($arrInvoiceNoTemp) != count(array_unique($arrInvoiceNoTemp))) {
            return [
                'success' => false,
                'message' => 'Duplicate invoice data in the payload.'
            ];
        }
        return [
            'success' => true,
            'data' => $arrInvoiceNoTemp
        ];
    }
}
