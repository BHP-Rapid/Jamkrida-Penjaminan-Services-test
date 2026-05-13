<?php

namespace App\Http\Controllers;

use App\Exceptions\NotFoundException;
use App\Helpers\ApiResponse;
use App\Helpers\AuthUserHelper;
use App\Services\PenjaminanTransactionService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PenjaminanTransactionController extends Controller
{
    //
    public function __construct(protected PenjaminanTransactionService $penjaminanService) {}

    public function index(Request $request)
    {
        try {
            $user = AuthUserHelper::getUser($request);
            $params = [
                'page' => $request->query('page', 1),
                'show_page' => $request->query('show_page', 10),
                'sort_column' => $request->query('sort_column', 'created_at'),
                'sort' => $request->query('sort', 'desc'),
                'search' => $request->query('search'),
                'filter' => $request->query('filter'),
                'mitra_id' => $request->query('mitra_id'),
            ];
            $result = $this->penjaminanService->getList($params, $user);
            return ApiResponse::success($result);
        } catch (ValidationException $e) {
            return ApiResponse::error(
                'Validation error',
                422,
                $e->errors()
            );
        } catch (NotFoundException $nfe) {
            return ApiResponse::error($nfe->getMessage(), $nfe->getStatus(), $nfe->getMessageData());
        } catch (Exception $ex) {
            Log::error("", ['exception' => $ex]);
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
        } catch (NotFoundException $nfe) {
            return ApiResponse::error($nfe->getMessage(), $nfe->getStatus(), $nfe->getMessageData());
        } catch (Exception $ex) {
            Log::error("", ['exception' => $ex]);
            return ApiResponse::error($ex->getMessage(), 500);
        }
    }

    public function getAdditionalDocument(Request $request)
    {
        try {
            $validated = $request->validate([
                'trx_no' => 'required|string|max:100',
                'product' => 'required|string|in:mlt,srtb,cstb,kmk,ku,kur,kpr,kkpbj',
            ], [
                'trx_no.required' => 'trx_no is required',
                'product.required' => 'product is required',
                'product.in' => 'product must be one of: mlt,srtb,cstb,kmk,ku,kur,kpr,kkpbj',
            ]);
            $payload = $validated;
            $result = $this->penjaminanService->getAdditionalDocProduct($payload);
            return ApiResponse::success($result);
        } catch (ValidationException $e) {
            return ApiResponse::error(
                'Validation error',
                422,
                $e->errors()
            );
        } catch (NotFoundException $nfe) {
            return ApiResponse::error($nfe->getMessage(), $nfe->getStatus(), $nfe->getMessageData());
        } catch (Exception $ex) {
            Log::error("", ['exception' => $ex]);
            return ApiResponse::error($ex->getMessage(), 500);
        }
    }

    public function  GetDetailCertificateByID(Request $request)
    {
        try {
            $validated = $request->validate([
                'trx_no' => 'required|string|max:100',
                'product' => 'required|string|in:mlt,srtb,cstb,kmk,ku,kur,kpr,kkpbj',
            ], [
                'trx_no.required' => 'trx_no is required',
                'product.required' => 'product is required',
                'product.in' => 'product must be one of: mlt,srtb,cstb,kmk,ku,kur,kpr,kkpbj',
            ]);
            $payload = $validated;
            $result = $this->penjaminanService->getDetailCertificateByID($payload);
            return ApiResponse::success($result);
        } catch (ValidationException $e) {
            return ApiResponse::error(
                'Validation error',
                422,
                $e->errors()
            );
        } catch (NotFoundException $nfe) {
            return ApiResponse::error($nfe->getMessage(), $nfe->getStatus(), $nfe->getMessageData());
        } catch (Exception $ex) {
            Log::error("", ['exception' => $ex]);
            return ApiResponse::error($ex->getMessage(), 500);
        }
    }

    public function getPenjaminanPKS(Request $request)
    {
        try {
            $user = AuthUserHelper::getUser($request);
            $result = $this->penjaminanService->getPenjaminanPks($user);
            return ApiResponse::success($result);
        } catch (ValidationException $e) {
            return ApiResponse::error(
                'Validation error',
                422,
                $e->errors()
            );
        } catch (NotFoundException $nfe) {
            return ApiResponse::error($nfe->getMessage(), $nfe->getStatus(), $nfe->getMessageData());
        } catch (Exception $ex) {
            Log::error("", ['exception' => $ex]);
            return ApiResponse::error($ex->getMessage(), 500);
        }
    }

    public function getLampiranByProduct(Request $request)
    {
        try {
            $user = AuthUserHelper::getUser($request);
            $validated = $request->validate([
                'mitra_id' => 'nullable|string',
                'module' => 'required|string|in:PENJAMINAN_SETTINGS,CLAIM_SETTINGS',
                'product_id' => 'required|string|in:mlt,srtb,cstb,kmk,ku,kur,kpr,kkpbj',
            ], [
                // 'mitra_id.required' => 'mitra_id is required',
                // 'mitra_id.in' => 'mitra_id must be one of: mlt,srtb,cstb,kmk,ku,kur,kpr,kkpbj',
                'module.required' => 'module is required',
                'module.in' => 'module must be one of: PENJAMINAN_SETTINGS,CLAIM_SETTINGS',
                'product_id.required' => 'product_id is required',
                'product_id.in' => 'product_id must be one of: mlt,srtb,cstb,kmk,ku,kur,kpr,kkpbj',
            ]);
            $payload = $validated;
            $result = $this->penjaminanService->getLampiranByProduct($payload, $user);
            return ApiResponse::success($result);
        } catch (ValidationException $e) {
            return ApiResponse::error(
                'Validation error',
                422,
                $e->errors()
            );
        } catch (NotFoundException $nfe) {
            return ApiResponse::error($nfe->getMessage(), $nfe->getStatus(), $nfe->getMessageData());
        } catch (Exception $ex) {
            Log::error("", ['exception' => $ex]);
            return ApiResponse::error($ex->getMessage(), 500);
        }
    }
}
