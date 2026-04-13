<?php

namespace App\Services;

use App\Helpers\AesHelper;
use App\Helpers\GenerateInvoiceMidtrans;
use App\Models\TrxSrtbInvoiceHeader;
use App\Repositories\PaymentgatewayRepository;
use App\Services\PaymentGateway\PaymentHandlerFactory;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Midtrans\Config;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;



class PaymentGatewayService
{
    public function __construct(
        protected PaymentgatewayRepository $repository
    ) {}

    public function createPayment(Request $request)
    {
        try {
            Config::$serverKey    = config('midtrans.server_key');
            Config::$isProduction = (bool) config('midtrans.is_production', false);
            Config::$isSanitized  = true;
            Config::$is3ds        = true;
            $key = base64_decode(config('services.secure.key'));
            $nowJakarta = Carbon::now('Asia/Jakarta');
            $results = [];
            $input = [
                'trx_no' => $request->input('trx_no'),
                'noSuratPermohonan' => $request->input('noSuratPermohonan'),
                'tenorId' => $request->input('tenorId'),
                'product' => $request->input('product'),
                'debiturList' => $request->input('debiturList', [])
            ];
            DB::beginTransaction();
            $debiturList =  $input['debiturList'];
            if ($input['product'] === 'srtb') {
                $results = $this->handlePaymentSrtb($input, $nowJakarta, $debiturList, $key);
            }
            if ($input['product'] === 'cstb') {
                // $result = $this->handlePaymentCstb()
            }

            DB::commit();
            return $results;
        } catch (Exception $ex) {
            throw new \Exception($ex->getMessage(), 500);
            // return ApiResponse::error($ex->getMessage(), 500);
        }
    }

    public function CancelPaymentMidtrans(Request $request)
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
            $orderId = $request->input('order_id');
            $product = $request->input('product');
            $nowJakarta = Carbon::now('Asia/Jakarta');
            DB::beginTransaction();
            switch ($product) {
                case 'mlt':
                    $this->cancelMlt($orderId, $nowJakarta);
                    break;

                // case 'srtb':
                //     $this->cancelSrtb($orderId, $nowJakarta);
                //     break;

                // case 'cstb':
                //     $this->cancelCstb($orderId, $nowJakarta);
                //     break;

                // case 'kpr':
                //     $this->cancelKPR($orderId, $nowJakarta);
                //     break;
                // case 'ajp':
                //     $this->cancelAJP($orderId, $nowJakarta);
                //     break;
                // case 'kkpbj':
                //     $this->cancelKKPBJ($orderId, $nowJakarta);
                //     break;

                // case 'ku':
                // case 'kur':
                // case 'kmk':
                //     $this->cancelDebiturProduct($orderId, $nowJakarta);
                //     break;
                // case 'kbg':
                //     $this->cancelKbg($orderId, $nowJakarta);
                //     break;

                default:
                    DB::rollBack();
                    return response()->json(['message' => 'Invalid product'], 400);
            }

            DB::commit();
        } catch (Exception $ex) {
            // return ApiResponse::error($ex->getMessage(), 500);
            throw new \Exception($ex->getMessage(), 500);
        }
    }

    private function handlePaymentSrtb(array $input, $nowJakarta, array $debiturList, string $key)
    {
        $dataHeader = $this->repository->getDetailSrtb(
            $input['trx_no'],
            $input['noSuratPermohonan']
        );

        if (!$dataHeader) {
            throw new \Exception('Data tidak ditemukan');
        }
        $decryptPhone = AesHelper::decrypt($dataHeader->phone_1, $key);
        $decryptEmail = AesHelper::decrypt($dataHeader->email_1, $key);
        $customers = [
            'first_name' => $dataHeader->principal_name,
            'last_name'  => $dataHeader->obligee_name,
            'email'      =>  $decryptEmail,
            'phone'      =>  $decryptPhone,
        ];
        $orderId = 'ORDER-' . now()->format('YmdHis') . '-' . mt_rand(1000, 9999);
        $totalIjp = 0;
        $items = [];
        $normalInvoice = collect($debiturList)->first(fn($x) => empty($x['isCollateral']));
        $collateralInvoice = collect($debiturList)->first(fn($x) => !empty($x['isCollateral']));
        $invoiceHeaderSrtb = null;
        $checkPayment = null;
        if (!empty($normalInvoice) && !empty($collateralInvoice)) {
            $totalIjp = (int) $normalInvoice['amount'] + $collateralInvoice['amount'];
            $getDataTenor = $this->repository->getTenorByProductId($dataHeader->id_trx_product);
            $checkPayment = $this->repository->getLastPaymentByInvoiceId($getDataTenor->invoice_id);
            if (empty($checkPayment)) {
                $invoiceHeaderSrtb = TrxSrtbInvoiceHeader::create([
                    'srtb_schedule_id' => $getDataTenor->srtb_schedule_id,
                    'invoice_scope' => 'Merge Payment',
                    'total_amount' => $totalIjp,
                    'status' => 'Unpaid',
                    'created_at' => $nowJakarta,
                    'tenor_sequence' => $input['tenorId'],
                    'is_manual' => 0
                ]);
                $getDataTenor->update([
                    'status' => 'Unpaid',
                    'amount' => (int) $normalInvoice['amount']  ?? 0,
                    'status_collateral' => 'Unpaid',
                    'collateral_amount' => $collateralInvoice['amount'] ?? 0,
                ]);
            }
            $items[] = [
                'id'       => (string) $dataHeader->no_surat_permohonan . '-' . ($normalInvoice['invoiceNumber'] ?? ''),
                'price'    => $totalIjp,
                'quantity' => 1,
                'name'  => "IJP dan Premi Permohonan {$dataHeader->no_surat_permohonan}",
            ];
        } else if (!empty($normalInvoice) && empty($collateralInvoice)) {
            // dd('test2');
            $totalIjp = (int) $normalInvoice['amount'];
            $getDataTenor = $this->repository->getTenorByProductId($dataHeader->id_trx_product);
            $checkPayment = $this->repository->getLastPaymentByInvoiceId($getDataTenor->invoice_id);
            if (empty($checkPayment)) {
                $invoiceHeaderSrtb = TrxSrtbInvoiceHeader::create([
                    'srtb_schedule_id' => $getDataTenor->srtb_schedule_id,
                    'invoice_scope' => 'Permohonan IJP Payment',
                    'total_amount' => $totalIjp,
                    'status' => 'Unpaid',
                    'created_at' => $nowJakarta,
                    'tenor_sequence' => $input['tenorId'],
                    'is_manual' => 0
                ]);
                $getDataTenor->update([
                    'status' => 'Unpaid',
                    'amount' => $totalIjp ?? 0,

                ]);
            }
            $items[] = [
                'id'       => (string) $dataHeader->no_surat_permohonan . '-' . ($normalInvoice['invoiceNumber'] ?? ''),
                'price'    => $totalIjp,
                'quantity' => 1,
                'name'  => "IJP dengan Nomor Permohonan {$dataHeader->no_surat_permohonan}",
            ];
        } else if (empty($normalInvoice) && !empty($collateralInvoice)) {
            $getDataTenor = $this->repository->getTenorByProductId($dataHeader->id_trx_product);
            $checkPayment = $this->repository->getLastPaymentByInvoiceId($getDataTenor->invoice_id);
            if (empty($checkPayment)) {
                $invoiceHeaderSrtb = TrxSrtbInvoiceHeader::create([
                    'srtb_schedule_id' => $getDataTenor->srtb_schedule_id,
                    'invoice_scope' => 'Collateral Payment',
                    'total_amount' => $collateralInvoice['amount'],
                    'status' => 'Unpaid',
                    'created_at' => $nowJakarta,
                    'tenor_sequence' => $input['tenorId'],
                    'is_manual' => 0
                ]);
                $getDataTenor->update([
                    'status_collateral' => 'Unpaid',
                    'collateral_amount' => $collateralInvoice['amount'] ?? 0,
                ]);
            }
            $amount = (int) ($collateralInvoice['amount'] ?? 0);
            $totalIjp += $amount;
            $items[] = [
                'id'       => (string) $dataHeader->no_surat_permohonan . '-' . ($collateralInvoice['invoiceNumber'] ?? ''),
                'price'    => $amount,
                'quantity' => 1,
                'name'  => "Collateral dengan Nomor Permohonan {$dataHeader->no_surat_permohonan}",
            ];
        }
        $params = [
            'transaction_details' => [
                'order_id'     => $orderId,
                'gross_amount' => $totalIjp,
            ],
            'customer_details' => $customers,
            'item_details'     => $items,
        ];
        if (empty($checkPayment)) {
            $created = $this->createNewPayment($params, $input['product'], $invoiceHeaderSrtb, $orderId, $totalIjp, $nowJakarta);
            $results[] = [
                'status' => 'Unpaid',
                'order_id' => $created['order_id'],
                'redirect_url' => $created['redirect_url'],
                'token' => $created['token'],
            ];
        } else {
            $invoiceHeader = TrxSrtbInvoiceHeader::where('srtb_invoice_id', $checkPayment->srtb_invoice_id)->first();
            $updated = $this->updateNewPayment($params,  $input['product'], $invoiceHeader, $orderId, $totalIjp, $nowJakarta);
            $results[] = [
                'status' => 'Unpaid',
                'order_id' => $updated['order_id'],
                'redirect_url' => $updated['redirect_url'],
                'token' => $updated['token']
            ];
        }
    }

    private function createNewPayment(array $params, string $product, mixed $invoiceFP, string $orderId, int|float $ijp, $nowJakarta): array
    {
        $snap = GenerateInvoiceMidtrans::createSnapToken($params);
        if (!$snap['success']) {
            throw new \RuntimeException(
                "Midtrans Snap error ({$snap['http_status']}): " .
                    ($snap['message'] ?? 'Unknown error')
            );
        }

        $snapToken = $snap['token'];
        $combineToken = $snap['redirect_url'];

        $handler = PaymentHandlerFactory::make($product);

        $handler->create(
            $invoiceFP,
            $ijp,
            $orderId,
            $snapToken,
            $combineToken,
            $nowJakarta
        );

        return [
            'token' => $snapToken,
            'redirect_url' => $combineToken,
            'order_id' => $orderId,
        ];
    }


    private function updateNewPayment(array $params, string $product, mixed $invoiceFP, string $orderId, int | float $ijp, $nowJakarta): array
    {
        // $audit = new AuditTransactionService();
        $snap = GenerateInvoiceMidtrans::createSnapToken($params);

        if (!$snap['success']) {
            throw new \RuntimeException(
                "Midtrans Snap error ({$snap['http_status']}): " .
                    ($snap['message'] ?? 'Unknown error')
            );
        }
        $snapToken    = $snap['token'];
        $combineToken = $snap['redirect_url'];

        // Resolve Handler Based On Product
        $handler = PaymentHandlerFactory::make($product);

        // Update Existing Payment Record
        $handler->update(
            $invoiceFP,
            $ijp,
            $orderId,
            $snapToken,
            $combineToken,
            $nowJakarta
        );

        return [
            'success'      => true,
            'token'        => $snapToken,
            'redirect_url' => $combineToken,
            'order_id'     => $orderId,
        ];
        // if (!$snap['success']) {
        //     throw new \RuntimeException(
        //         "Midtrans Snap error ({$snap['http_status']}): " .
        //             ($snap['message'] ?? 'Unknown error')
        //     );
        //     $auditPayload = [
        //         'body' => $params,
        //         'midtrans' => [
        //             'endpoint'    => $snap['redirect_url'] ?? null,
        //             'http_status' => $snap['http_status'] ?? null,
        //             'success'     => (bool) ($snap['success'] ?? false),
        //             'message'     => $snap['message'] ?? null,
        //             'raw'         => $snap['raw'] ?? null,
        //             'order_id'    => $orderId,
        //             'product'     => $product,
        //             'action'      => 'update_payment',
        //         ],
        //     ];
        //     $isSuccess = (bool) $snap['success'];
        //     // $auditOk = $audit->logAuditTrail(
        //     //     'POST',
        //     //     $snap['redirect_url'] ?? null,
        //     //     null,
        //     //     auth('sanctum')->user()->email,
        //     //     auth('sanctum')->user()->role,
        //     //     json_encode($auditPayload),
        //     //     auth('sanctum')->user()->user_id,
        //     //     auth('sanctum')->user()->name,
        //     //     $snap['success'] ? true :  false
        //     // );

        //     // if (!$auditOk) {
        //     //     throw new \Exception("Failed to insert audit trail record.");
        //     // }
        //     // if (!$snap['success']) {
        //     //     return response()->json([
        //     //         'success' => false,
        //     //         'message' => "Midtrans Snap error ({$snap['http_status']}): " . ($snap['message'] ?? 'Unknown error'),
        //     //         'errors'  => $snap['raw'] ?? null,
        //     //     ], 500);
        //     // }

        // }

    }


    private function cancelMlt($orderId, $nowJakarta)
    {
        $payment = $this->repository->cancelPaymentMlt($orderId);
        if (!$payment) {
            return response()->json(['message' => 'Payment not found'], 404);
        }
        if (in_array(strtoupper($payment->status), ['PAID', 'SETTLED'])) {
            return response()->json([
                'message' => 'Paid payment cannot be cancelled'
            ], 409);
        }
        $invoiceIds = DB::table('transaction_payment_gateway')
            ->where('order_id', $orderId)
            ->pluck('invoice_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
        if (empty($invoiceIds)) {
            DB::rollBack();
            return response()->json(['message' => 'No invoice found for this order_id'], 404);
        }
        DB::table('multiguna_tenor_schedule as mts')
            ->join('transaction_payment_gateway as mpg', 'mpg.invoice_id', '=', 'mts.invoice_id')
            ->where('mpg.order_id', $orderId)
            ->update([
                'mts.status' => 'Pending',
                'mts.updated_at' => $nowJakarta,
            ]);
        DB::table('transaction_payment_gateway')
            ->where('order_id', $orderId)
            ->delete();

        DB::table('transaction_invoice_header')
            ->whereIn('invoice_id', $invoiceIds)
            ->delete();
    }
}
