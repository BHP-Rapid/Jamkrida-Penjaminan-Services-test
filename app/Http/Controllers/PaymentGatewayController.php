<?php

namespace App\Http\Controllers;

use App\Services\PaymentGatewayService;
use Exception;
use Illuminate\Http\Request;

class PaymentGatewayController extends Controller
{
    public function __construct(
        protected PaymentGatewayService $paymentGatewayService
    ) {}

    public function generatePaymentGateway(Request $request)
    {
        try {
            return $this->paymentGatewayService->createPayment($request);

        } catch (Exception $ex) {
            return response()->json([
                'success' => false,
                'message' => $ex->getMessage(),
            ], 500);
        }
    }
}