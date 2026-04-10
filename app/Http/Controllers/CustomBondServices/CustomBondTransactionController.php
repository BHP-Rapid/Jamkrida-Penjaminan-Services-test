<?php

namespace App\Http\Controllers\CustomBondServices;

use App\Http\Controllers\Controller;
use App\Services\CustomBondServices\CustomBond as CustomBondServicesCustomBondTransactionService;
use App\Services\PenjaminanService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Exception;

class CustomBondTransactionController extends Controller
{
    protected $service;
    use ValidatesRequests;

    public function __construct(CustomBondServicesCustomBondTransactionService $service)
    {
        $this->service = $service;
    }

    public function show(Request $request)
    {
        try {
            $data = $this->service->getDetail(
                $request->query('trx_no'),
                $request->query('no_surat_permohonan')
            );

            if (!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data not found.'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $ex) {
            return response()->json([
                'success' => false,
                'message' => 'Error While Get Data Custom Bond: ' . $ex->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $user = auth('sanctum')->user();

            $result = $this->service->store($request->all(), $user);

            if (isset($result['error'])) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message']
                ], $result['code']);
            }

            return response()->json($result);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            return response()->json([
                'message' => $ve->getMessage(),
                'error' => $ve->errors()
            ], 422);
        } catch (\Exception $ex) {
            return response()->json([
                'success' => false,
                'message' => 'Error While Insert Custom Bond: ' . $ex->getMessage()
            ], 500);
        }
    }

    public function UpdateDraft(Request $request, string $trxNo)
    {
        $user = auth('sanctum')->user();

        $this->validate($request, [
            'data.trx_status' => 'required|string|in:D,NA',
            'data.noSuratPermohonan' => 'required|string|max:50',
            'data.tglSuratPermohonan' => 'required|date_format:Y-m-d',
            'data.jenisPernyataan' => 'nullable|string|max:50',
            'data.skemaPenalty' => 'nullable|string|max:50',
            'data.sektor' => 'nullable|string|max:50',
            'data.namaPrincipal' => 'nullable|string|max:255',
            'data.namaObligee' => 'nullable|string|max:255',
            'data.isBast' => 'nullable|boolean',
            'data.namaProyek' => 'nullable|string|max:100',
            'data.nilaiProyek' => 'nullable|numeric|min:0',
            'data.nilaiBond' => 'nullable|numeric|min:0',
            'data.nilaiBondPersentase' => 'nullable|numeric|min:0',
            'data.periodeAwalBerlaku' => 'nullable|date_format:Y-m-d',
            'data.periodeAkhirBerlaku' => 'nullable|date_format:Y-m-d',
            'data.jangkaWaktu' => 'nullable|numeric|min:0',
            'data.propinsi' => 'nullable|string|max:50',
            'data.jenisSuratPerjanjian' => 'nullable|string|max:64',
            'data.noSuratPerjanjian' => 'nullable|string|max:64',
            'data.tglSuratPerjanjian' => 'nullable|date_format:Y-m-d',
            'data.attachments' => 'nullable|array',
            'data.attachments.*.file' => 'file|mimes:pdf,jpg,jpeg,png|max:2048',
            'data.attachments.*.lampiran_id' => 'required|string',
            'data.tarif' => 'nullable|numeric|min:0'
        ]);

        try {
            $result = $this->service->updateDraft($request->all(), $trxNo, $user);

            if (isset($result['error'])) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message']
                ], $result['code']);
            }

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function ApprovePenjaminanCSTB(Request $request)
    {
        $trx_no = $request->trxNo;
        // dd($trx_no);
        $method = $request->method();
        $fullUrl = $request->fullUrl();
        try {
            (new PenjaminanService())->approveCSTBPenjaminan(
                $trx_no,
                auth('sanctum')->user()->user_id,
                auth('sanctum')->user()->name,
                "Perorangan"
            );
            return response()->json([
                'success' => true,
                'message' => 'Penjaminan Custom Bond successfully approved.'
            ]);
        } catch (Exception $ex) {
            return response()->json([
                'success' => false,
                'message' => 'Error while approving Penjaminan Custom Bond (' . $ex->getMessage() . ')'
            ], 500);
        }
    }
}
