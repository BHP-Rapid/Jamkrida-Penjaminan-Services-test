<?php

namespace App\Services;

use App\Helpers\ApiResponse;
use App\Repositories\PaymentgatewayRepository;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Midtrans\Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;



class PaymentGatewayService
{
    public function __construct(
        protected PaymentgatewayRepository $repository
    ) {}

    public function createPayment(Request $request): JsonResponse
    {
        try {
            // 🔥 CONFIG MIDTRANS
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
                $results = $this->handlePaymentSrtb($input, $nowJakarta, $debiturList,$key);
            }

            DB::commit();
            return ApiResponse::success($results);
        } catch (Exception $ex) {
            return ApiResponse::error($ex->getMessage(), 500);
        }
    }

    private function handlePaymentSrtb(array $input, $nowJakarta, array $debiturList,string $key)
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
    }

    // private function handleMltPayment(array $input, $nowJakarta)
    // {
    //     $results = [];
    //     $tenorId = $input['tenorId'];
    //     $idList = collect($input['debiturList'])
    //         ->pluck('IdDebitur')
    //         ->filter()
    //         ->values()
    //         ->all();
    //     $dataHeader = PenjaminanTransaction::query()
    //         ->from('transaction_penjaminan_header as tph')
    //         ->join('multiguna_transaction as mt', 'tph.trx_no', '=', 'mt.trx_no')
    //         ->where('tph.trx_no', $input['trx_no'])
    //         ->where('tph.no_surat_permohonan', $input['noSuratPermohonan'])
    //         ->select(['tph.*', 'mt.*'])
    //         ->first();

    //     if (!$dataHeader) {
    //         throw new Exception('Data tidak ditemukan');
    //     }

    //     $dataDebitur = MultigunaDebitur::where('multiguna_trx_id', $dataHeader->id_multiguna)
    //         ->whereIn('id_trx_debitur', $idList)
    //         ->get();

    //     if ($dataDebitur->isEmpty()) {
    //         throw new Exception('Data Debitur tidak ditemukan');
    //     }

    //     if ($dataDebitur->count() > 1) {
    //         return $this->handleMultipleDebitur($dataHeader, $dataDebitur, $tenorId, $nowJakarta);
    //     }
    //     return $this->handleSingleDebitur($dataHeader, $dataDebitur, $tenorId, $nowJakarta);
    // }
}
