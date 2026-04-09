<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Services\PenjaminanTransactionService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

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
        try {
            return $this->penjaminanService->storeAdditionalDoc($req);
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

    public function getAdditionalDocument(Request $req): JsonResponse
    {
        try {
            $result = $this->penjaminanService->getAdditionalDocProduct($req);
            return ApiResponse::success($result);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 500);
        }
    }
}
