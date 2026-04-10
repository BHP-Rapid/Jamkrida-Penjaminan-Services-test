<?php

namespace App\Http\Controllers;

use App\Helper\ApiResponse;
use App\Helper\ValidateDebitur;
use App\Models\TenantMitra;
use App\Services\MultigunaService;
use Exception;
use Illuminate\Http\Request;

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
}
