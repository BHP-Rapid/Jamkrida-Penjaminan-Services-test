<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Services\PaymentGatewayService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaymentGatewayController extends Controller
{
    public function __construct(
        protected PaymentGatewayService $paymentGatewayService
    ) {}

    public function generatePaymentGateway(Request $request)
    {
        try {
            $result = $this->paymentGatewayService->createPayment($request);
            return ApiResponse::success($result);
        } catch (Exception $ex) {
            return response()->json([
                'success' => false,
                'message' => $ex->getMessage(),
            ], 500);
        }
    }

    public function cancelPaymentMidtrans(Request $request)
    {
        try {
            $result = $this->paymentGatewayService->CancelPaymentMidtrans($request);
            return ApiResponse::success($result);
        } catch (ValidationException $e) {
            return ApiResponse::error(
                'Validation error',
                422,
                $e->errors()
            );
        } catch (Exception $ex) {
            return ApiResponse::error(
                $ex->getMessage(),
                500
            );
        }
    }
}
