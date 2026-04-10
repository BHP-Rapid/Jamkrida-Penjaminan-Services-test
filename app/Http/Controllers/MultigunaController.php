<?php

namespace App\Http\Controllers;

use App\Helper\ApiResponse;
use App\Services\MultigunaService\MultigunaService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class MultigunaController extends Controller
{
    public function __construct(protected MultigunaService $multigunaService) {}


    public function show($id)
    {
        if (empty($id)) {
            return ApiResponse::error('ID is required', 400);
        }

        try {
            $data = $this->multigunaService->getMultigunaDetailWithAttachments($id);

            return ApiResponse::success($data, 'Data retrieved successfully');
        } catch (Exception $ex) {
            return ApiResponse::error('Error While Get Data Multiguna: ' . $ex->getMessage(), 500);
        }
    }

    public function updateDraft(Request $request, $trxNo)
    {
        $user = auth('sanctum')->user();
        try {
            $this->multigunaService->updateMultigunaDraft(
                $trxNo,
                $request->input(),
                $user->user_id ?? null,
                $user->name ?? null
            );

            return ApiResponse::success([], 'Data berhasil diupdate');
        } catch (ModelNotFoundException $ex) {
            return ApiResponse::error('Data tidak ditemukan', 404);
        } catch (Exception $ex) {
            return ApiResponse::error('Error While Updating Multiguna: ' . $ex->getMessage(), 500);
        }
    }
}
