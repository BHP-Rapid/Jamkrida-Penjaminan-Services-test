<?php

namespace App\Http\Controllers\SuretyBondTransactionServices;

use App\Http\Controllers\Controller;
use App\Services\SuretyBondServices\SuretyBond as SuretyBondTransactionService;
use Illuminate\Http\Request;


class SuretyBondTransactionController extends Controller
{
    protected $service;

    public function __construct(SuretyBondTransactionService $service)
    {
        $this->service = $service;
    }

    private function errorResponse(\Exception $e)
    {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], $e->getCode() ?: 500);
    }

    public function show(Request $request)
    {
        try {
            $data = $this->service->handleShow($request);

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }
    }

    public function store(Request $request)
    {
        try {
            $message = $this->service->handleStore($request);

            return response()->json([
                'success' => true,
                'message' => $message
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }
    }

    public function update(Request $request, $trxNo)
    {
        try {
            $message = $this->service->handleUpdate($request, $trxNo);

            return response()->json([
                'success' => true,
                'message' => $message
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }
    }

    public function submitDraft(Request $request, $trxNo)
    {
        try {
            $message = $this->service->handleSubmitDraft($request, $trxNo);

            return response()->json([
                'success' => true,
                'message' => $message
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }
    }
}
