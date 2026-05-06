<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Services\PenjaminanTransactionService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
        } catch (ValidationException $e) {
            return ApiResponse::error(
                'Validation error',
                422,
                $e->errors()
            );
        } catch (Exception $ex) {
            return ApiResponse::error($ex->getMessage(), 500);
        }
    }

    public function uploadAdditionalDoc(Request $req)
    {
        try {
            $validated  = $req->validate([
                'penjaminan_no' => 'required|string',
                'no_surat_permohonan' => 'required|string',
                'FormFile.*' => 'string',
            ]);
            $payload = array_merge($validated, [
                '_meta' => [
                    'method' => $req->method(),
                    'url' => $req->fullUrl(),
                ]
            ]);
            $result = $this->penjaminanService->storeAdditionalDoc($payload);
            return ApiResponse::success($result, 'Additional document uploaded successfully');
        } catch (ValidationException $e) {
            return ApiResponse::error(
                'Validation error',
                422,
                $e->errors()
            );
        } catch (Exception $ex) {
            return ApiResponse::error($ex->getMessage(), 500);
        }
    }

    public function getAdditionalDocument(Request $req): JsonResponse
    {
        try {
            $result = $this->penjaminanService->getAdditionalDocProduct($req);
            return ApiResponse::success($result);
        } catch (ValidationException $e) {
            return ApiResponse::error(
                'Validation error',
                422,
                $e->errors()
            );
        } catch (Exception $ex) {
            return ApiResponse::error($ex->getMessage(), 500);
        }
    }

    public function  GetDetailCertificateByID(Request $req)
    {
        try {
            $result = $this->penjaminanService->getDetailCertificateByID($req);
            return ApiResponse::success($result);
        } catch (ValidationException $e) {
            return ApiResponse::error(
                'Validation error',
                422,
                $e->errors()
            );
        } catch (Exception $ex) {
            return ApiResponse::error($ex->getMessage(), 500);
        }
    }
}
