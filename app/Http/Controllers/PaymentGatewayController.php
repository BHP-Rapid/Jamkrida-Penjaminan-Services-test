<?php

namespace App\Http\Controllers;

use App\Services\PaymentGatewayService;
use Exception;
use Illuminate\Http\Request;

class PaymentGatewayController extends Controller
{
    //
    public function __construct(protected PaymentGatewayService $paymentGatewayService) {}

    public function GeneratePaymentGateway(Request $request) {
        try{


        } catch (Exception $ex){
            
        }
    }
}
