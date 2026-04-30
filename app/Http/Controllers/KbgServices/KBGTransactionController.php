<?php

namespace App\Http\Controllers\KbgServices;

use App\Exceptions\NotFoundException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\KBGServices\KontraBaknGaransiService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KBGTransactionController extends Controller
{
    private $kbgService;
    public function __construct(KontraBaknGaransiService $service) {
        $this->kbgService = $service;
    }

    public function store(Request $request)
    {
        try {
            $user = auth('sanctum')->user();
            $result = $this->kbgService->kbgStore($request, $user);
            if(!$result['success'])
            {
                return response()->json([
                    'success' => false,
                    'message' => $result['message']
                ], 422);
            }
            return ApiResponse::success(null, 'Successfully created Penjaminan KBG.');
        } catch (NotFoundException $nfe) {
            return ApiResponse::error(
                $nfe->getMessage(),
                $nfe->getStatus()
            );
        } catch (ValidationException $ve) {
            return ApiResponse::error(
                'Validation error',
                422,
                $ve->errors()
            );
        } catch (Exception $ex) {
            // dd($ex->getMessage());
            return ApiResponse::error(
                $ex->getMessage()
                // $ex->getCode() ?? 500
            );
        }
    }
}
