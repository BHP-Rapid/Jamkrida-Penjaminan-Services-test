<?php

namespace App\Services;

use App\Helpers\AesHelper;
use App\Helpers\GenerateInvoiceMidtrans;
use App\Helpers\SendEmailNotification;
use App\Models\MultigunaTenorSchedule;
use App\Models\TrxSrtbInvoiceHeader;
use App\Repositories\PaymentgatewayRepository;
use App\Services\PaymentGateway\PaymentHandlerFactory;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Midtrans\Config;
use Illuminate\Http\Request;


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

    public function CancelPaymentMidtrans(array $payload): array
    {
        try {
            if (!isset($payload['order_id'], $payload['product'])) {
                throw new Exception('Invalid payload structure', 400);
            }
            $orderId = $payload['order_id'];
            $product = $payload['product'];
            $nowJakarta = Carbon::now('Asia/Jakarta');
            DB::beginTransaction();
            switch ($product) {
                case 'mlt':
                    $this->cancelMlt($orderId, $nowJakarta);
                    break;
                default:
                    throw new Exception('Invalid product', 400);
            }
            DB::commit();
            $result = [
                'order_id' => $orderId,
                'product'  => $product,
                'status'   => 'cancelled',
                'cancelled_at' => $nowJakarta->toDateTimeString(),
            ];
            return $result;
        } catch (Exception $ex) {
            DB::rollBack();
            throw $ex;
        }
    }

    public function CheckPaymentMidtrans(array $payload): array
    {
        try {
            $key = base64_decode(config('services.secure.key'));
            $trxNo = $payload['trx_no'];
            $product = $payload['product'];
            $noSuratPermohonan = $payload['no_surat_permohonan'];
            $listDebitur = $payload['list_debitur'];
            $orderId = $payload['order_id'];
            $notifications = [];
            $result = [];
            $debiturIds = array_map(fn($d) => $d['IdDebitur'], $listDebitur);
            DB::beginTransaction();
            switch ($product) {
                case 'mlt':
                    $result = $this->validateMlt($trxNo, $noSuratPermohonan, $orderId, $listDebitur);
                    $trxName = $this->sendPaymentToCreatio(
                        $noSuratPermohonan,
                        $result['payloadCore']
                    );

                    $this->repository->updateNoKwitansiByDebiturIds($debiturIds, $trxName, $product);
                    $notifications = $this->handlePaymentNotifications(
                        $result['getListDebitur'],
                        $result['createdById'],
                        $trxNo,
                        $noSuratPermohonan,
                        $key,
                        $product
                    );

                    break;
                case 'srtb':
                    $result = $this->validateSrtb($trxNo, $noSuratPermohonan, $orderId, $listDebitur);
                    $trxName = $this->sendPaymentToCreatio(
                        $noSuratPermohonan,
                        $result['payloadCore']
                    );
                    $this->repository->updateNoKwitansiByDebiturIds($debiturIds, $trxName, $product);
                    $notifications = $this->handlePaymentNotifications(
                        $result['getListDebitur'],
                        $result['createdById'],
                        $trxNo,
                        $noSuratPermohonan,
                        $key,
                        $product
                    );
                    break;
                case 'cstb':
                    $result = $this->validateCstb($trxNo, $noSuratPermohonan, $orderId, $listDebitur);
                    $trxName = $this->sendPaymentToCreatio($noSuratPermohonan, $result['payloadCore']);
                    $this->repository->updateNoKwitansiByDebiturIds($debiturIds, $trxName, $product);
                    $notifications = $this->handlePaymentNotifications(
                        $result['getListDebitur'],
                        $result['createdById'],
                        $trxNo,
                        $noSuratPermohonan,
                        $key,
                        $product
                    );
                    break;
                default:
                    throw new Exception('Invalid product', 400);
            }
            $this->repository->insertNotifications($notifications);

            return [
                "orderId" => "Status Pembayaran " . $result["order_status"]['status'],
            ];
            DB::commit();
        } catch (Exception $ex) {
            throw $ex;
        }
    }

    private function validateMlt(string $trxNo, string $noSuratPermohonan, string $orderId, array $listDebitur): array
    {
        $nowJakarta = Carbon::now('Asia/Jakarta');
        $dataHeader = $this->repository->getDetailMlt($trxNo, $noSuratPermohonan);
        if (!$dataHeader) {
            throw new \Exception(`Data Surat Permohonan $noSuratPermohonan ditemukan`);
        }
        $checkOrderId = $this->repository->checkOrderMltById($trxNo, $noSuratPermohonan, $orderId);
        if (!$checkOrderId) {
            throw new \Exception(`Data Order ID $orderId ditemukan`);
        }
        $order_status = GenerateInvoiceMidtrans::checkSnapStatus($checkOrderId->order_id);
        $getDetailAfterPayment = (object) $order_status['raw'];
        $bankType = "";
        if ($getDetailAfterPayment->payment_type == "bank_transfer") {
            if (isset($getDetailAfterPayment->va_numbers[0])) {
                $bankType = $getDetailAfterPayment->va_numbers[0]['bank'];
                $virtualAccount = $getDetailAfterPayment->va_numbers[0]['va_number'];
            }
        }

        $this->repository->UpdateInvoiceDetailMlt($checkOrderId->invoice_id, $getDetailAfterPayment, $order_status['status'], $nowJakarta);
        $this->repository->UpdateInvoiceHeaderMlt($checkOrderId->invoice_id, $order_status['status'], $nowJakarta);
        $this->repository->UpdateTenorInvoiceMlt($checkOrderId->invoice_id, $order_status['status'], $nowJakarta);


        $getListDebitur = $this->repository->getListDebiturMlt($dataHeader->id_multiguna, $listDebitur, $orderId);
        $payloadCore = [
            "NoSuratPermohonan" => $dataHeader->no_surat_permohonan,
            "ListDebitur" => $getListDebitur->map(function ($debitur) {
                return [
                    "no_sp_detail" => $debitur->no_sp_detail,
                    "invoice_number" => $debitur->invoice_number,
                    "tenor_sequence" => $debitur->tenor_sequence,
                    "total_amount" => $debitur->total_amount
                ];
            })->values()->all(),
        ];
        $result = [
            "createdById" => $dataHeader->created_by_id,
            "getListDebitur" => $getListDebitur,
            "payloadCore" => $payloadCore,
            "orderStatus" => $order_status
        ];
        return $result;
    }

    private function validateSrtb(string $trxNo, string $noSuratPermohonan, string $orderId, array $listDebitur): array
    {
        $nowJakarta = Carbon::now('Asia/Jakarta');
        $dataHeader = $this->repository->getDetailSrtb($trxNo, $noSuratPermohonan);
        if (!$dataHeader) {
            throw new \Exception(`Data Surat Permohonan $noSuratPermohonan tidak ditemukan`);
        }
        $checkOrderId = $this->repository->checkOrderSrtbById($orderId);
        if (!$checkOrderId) {
            throw new \Exception(`Data Order ID $orderId ditemukan`);
        }
        $order_status = GenerateInvoiceMidtrans::checkSnapStatus($checkOrderId->order_id);
        $getDetailAfterPayment = (object) $order_status['raw'];
        $bankType = "";
        if ($getDetailAfterPayment->payment_type == "bank_transfer") {
            if (isset($getDetailAfterPayment->va_numbers[0])) {
                $bankType = $getDetailAfterPayment->va_numbers[0]['bank'];
                $virtualAccount = $getDetailAfterPayment->va_numbers[0]['va_number'];
            }
        }

        $this->repository->UpdateInvoiceDetailSrtb($checkOrderId->srtb_invoice_id, $getDetailAfterPayment, $order_status['status'], $nowJakarta);
        $this->repository->UpdateInvoiceHeaderSrtb($checkOrderId->srtb_invoice_id, $order_status['status'], $nowJakarta);

        foreach ($listDebitur as $deb) {
            $isCollateral = (bool) $deb['isCollateral'];
            $row = [
                'updated_at' => $nowJakarta,
            ];

            if ($isCollateral) {
                $invoiceColumn = 'invoice_number_collateral';
                $row['status_collateral'] = $order_status['status'];
            } else {
                $invoiceColumn = 'invoice_number';
                $row['status'] = $order_status['status'];
            }

            $this->repository->updateSingleSrtbTenorStatus(
                $checkOrderId->srtb_schedule_id,
                $invoiceColumn,
                $deb['invoiceNumber'],
                $row
            );
        }

        $listDebitur = array_map(function ($item) {
            $item['invoice_number'] = $item['invoiceNumber'];
            $item['total_amount'] = $item['amount'];
            $item['no_sp_detail'] = null;
            unset($item['amount']);
            unset($item['isCollateral']);
            unset($item['invoiceNumber']);
            return $item;
        }, $listDebitur);

        $payloadCore = [
            "NoSuratPermohonan" => $noSuratPermohonan,
            "ListDebitur" => $listDebitur,
        ];
        $result = [
            "createdById" => $dataHeader->created_by_id,
            // "getListDebitur" => $getListDebitur,
            "payloadCore" => $payloadCore,
            "orderStatus" => $order_status
        ];
        return $result;
    }

    private function validateCstb(string $trxNo, string $noSuratPermohonan, string $orderId, array $listDebitur): array
    {
        $nowJakarta = Carbon::now('Asia/Jakarta');
        $dataHeader = $this->repository->getDetailCstb($trxNo, $noSuratPermohonan);
        if (!$dataHeader) {
            throw new \Exception(`Data Surat Permohonan $noSuratPermohonan tidak ditemukan`);
        }
        $checkOrderId = $this->repository->checkOrderSrtbById($orderId);
        if (!$checkOrderId) {
            throw new \Exception(`Data Order ID $orderId tidak ditemukan`);
        }
        $order_status = GenerateInvoiceMidtrans::checkSnapStatus($checkOrderId->order_id);
        $getDetailAfterPayment = (object) $order_status['raw'];
        $bankType = "";
        if ($getDetailAfterPayment->payment_type == "bank_transfer") {
            if (isset($getDetailAfterPayment->va_numbers[0])) {
                $bankType = $getDetailAfterPayment->va_numbers[0]['bank'];
                $virtualAccount = $getDetailAfterPayment->va_numbers[0]['va_number'];
            }
        }

        $this->repository->UpdateInvoiceDetailCstb($checkOrderId->id_bond, $getDetailAfterPayment, $order_status['status'], $nowJakarta);
        $this->repository->UpdateInvoiceHeaderCstb($checkOrderId->id_bond, $order_status['status'], $nowJakarta);
        $this->repository->UpdateTenorInvoiceCstb($checkOrderId->id_bond, $order_status['status'], $nowJakarta);

        $listDebitur = array_map(function ($item) {
            $item['invoice_number'] = $item['invoiceNumber'];
            $item['total_amount'] = $item['amount'];
            $item['no_sp_detail'] = null;
            unset($item['amount']);
            unset($item['isCollateral']);
            unset($item['invoiceNumber']);
            return $item;
        }, $listDebitur);
        $payloadCore = [
            "NoSuratPermohonan" => $noSuratPermohonan,
            "ListDebitur" => $listDebitur,
        ];
        $result = [
            "createdById" => $dataHeader->created_by_id,
            // "getListDebitur" => $getListDebitur,
            "payloadCore" => $payloadCore,
            "orderStatus" => $order_status
        ];
        return $result;
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
            throw new \Exception('Payment not found', 404);
        }
        if (in_array(strtoupper($payment->status), ['PAID', 'SETTLED'])) {
            throw new \Exception('Paid payment cannot be cancelled', 409);
        }
        $invoiceIds = $this->repository->getInvoiceIdsByOrderId($orderId);
        if (empty($invoiceIds)) {
            throw new \Exception('No invoice found for this order_id', 404);
        }

        $this->repository->resetMltScheduleStatus($orderId, $nowJakarta);
        $this->repository->deletePaymentGatewayByOrderId($orderId);
        $this->repository->deleteInvoiceHeaderByInvoiceIds($invoiceIds);
    }


    private function sendPaymentToCreatio(string $noSuratPermohonan, array $payloadCore): string
    {
        $creatio = new CreatioService();

        $response = $creatio->request(
            'post',
            '/0/rest/PembayaranWebService/PembayaranAutoV2',
            $payloadCore
        );
        if ($response->status() !== 200) {
            throw new Exception(
                "Failed to Send Payment Status Integration {$noSuratPermohonan} to Core Creatio API with status: {$response->status()}",
                500
            );
        }
        $bodyResponse = json_decode($response->body(), true);

        if (!$bodyResponse) {
            throw new Exception(
                "Invalid response from Creatio API for {$noSuratPermohonan}",
                500
            );
        }
        if (($bodyResponse['Success'] ?? false) !== true) {
            $message = $bodyResponse['Message'] ?? 'Unknown error';

            throw new Exception(
                "Failed to Send Payment Status Integration {$noSuratPermohonan} to Core Creatio API with message: {$message}",
                500
            );
        }
        if (empty($bodyResponse['TrxName'])) {
            throw new Exception(
                "Creatio API response missing TrxName for {$noSuratPermohonan}",
                500
            );
        }
        return $bodyResponse['TrxName'];
    }


    private function handlePaymentNotifications(array $debiturList, int|string $createdById, string $trxNo, string $noSuratPermohonan, string $key, string $product): array
    {
        $notifications = [];
        $decryptEmail = fn($value) => $value
            ? AesHelper::decrypt($value, $key)
            : null;

        foreach ($debiturList as $deb) {
            $notifications[] =
                [
                    'mitra_user_id' => $createdById,
                    'title' => "- Peringatan Pembayaran - Order {$trxNo} Telah Berhasil",
                    'message' => " Halo {$deb->debitur_name},

                            Kami ingin menyampaikan bahwa pembayaran untuk Nomor Surat Permohonan {$noSuratPermohonan}
                            dengan Nomor Transaksi {$deb->order_id} telah berhasil diproses.

                            Saat ini pembayaran Anda sedang dalam proses validasi oleh Admin.
                            Silakan melakukan pengecekan secara berkala pada Nomor Surat Permohonan Anda
                            untuk mengetahui pembaruan status selanjutnya.

                            Terima kasih atas perhatian dan kerja sama Anda.
                                        ",
                    'is_read' => false,
                ];

            $email = $decryptEmail($deb->email_1);
            if ($email) {
                SendEmailNotification::sendEmail(
                    $email,
                    $deb->debitur_name,
                    $noSuratPermohonan,
                    $trxNo,
                    now()->toDateTimeString(),
                    $deb->amount
                );
            }
        }

        return $notifications;
    }
}
