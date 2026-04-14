<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Services\PaymentGatewayService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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
            $validator = Validator::make(
                $request->all(),
                [
                    'order_id' => ['required', 'string'],
                    'trx_no'   => ['required', 'string'],
                    'product'  => ['nullable', 'string'],
                ],
                [
                    'order_id.required' => 'order_id is required',
                    'trx_no.required'   => 'trx_no is required',
                    'product.string'    => 'product must be a string',
                ]
            );
            if ($validator->fails()) {
                throw new ValidationException($validator);
            }
            $payload = [
                'trx_no'               => $request->input('trx_no'),
                'product'              => $request->input('product'),
                'order_id'             => $request->input('order_id'),
            ];
            $result = $this->paymentGatewayService->CancelPaymentMidtrans($payload);
            return ApiResponse::success($result, 'Cancel payment success');
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

    public function CheckPaymentMidtrans(Request $request)
    {
        try {
            $validator = Validator::make(
                $request->all(),
                [
                    'trx_no'               => ['required', 'string'],
                    'product'              => ['required', 'string', 'in:mlt,srtb,cstb,kmk,ku,kur,kpr,kkpbj'],
                    'no_surat_permohonan'  => ['nullable', 'string'],
                    'list_debitur'         => ['nullable', 'array'],
                    'list_debitur.*'       => ['string'],
                    'order_id'             => ['required', 'string'],
                ],
                [
                    'trx_no.required'              => 'trx_no is required',
                    'trx_no.string'                => 'trx_no must be a string',

                    'product.required'             => 'product is required',
                    'product.string'               => 'product must be a string',
                    'product.in'                   => 'product must be one of: mlt,srtb,cstb,kmk,ku,kur,kpr,kkpbj',

                    'no_surat_permohonan.string'   => 'no_surat_permohonan must be a string',

                    'list_debitur.array'           => 'list_debitur must be an array',
                    'list_debitur.*.string'        => 'each debitur item must be a string',

                    'order_id.required'            => 'order_id is required',
                    'order_id.string'              => 'order_id must be a string',
                ]
            );

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }
            $payload = [
                'key'                  => base64_decode(config('services.secure.key')),
                'trx_no'               => $request->input('trx_no'),
                'product'              => $request->input('product'),
                'no_surat_permohonan'  => $request->input('no_surat_permohonan'),
                'list_debitur'         => $request->input('list_debitur', []),
                'order_id'             => $request->input('order_id'),
            ];
            $result = $this->paymentGatewayService->CheckPaymentMidtrans($payload);
            return ApiResponse::success($result, 'Check payment success');
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
