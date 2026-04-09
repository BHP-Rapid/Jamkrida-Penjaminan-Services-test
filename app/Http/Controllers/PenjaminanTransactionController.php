<?php

namespace App\Http\Controllers;

use App\Services\PenjaminanTransactionService;
use Illuminate\Http\Request;

class PenjaminanTransactionController extends Controller
{
    //
    public function __construct(protected PenjaminanTransactionService $penjaminanService) {}

    public function index(Request $request)
    {
        try {
            $result = $this->penjaminanService->getList($request->all());
            return response()->json([
                'success' => true,
                'data' => $result
            ]);
        } catch (\Exception $ex) {
            return response()->json([
                'message' => 'Error occurred while fetching data',
                'error' => $ex->getMessage()
            ], 500);
        }
    }

    public function uploadAdditionalDoc(Request $req)
    {
        return $this->penjaminanService->storeAdditionalDoc($req);
    }
}
