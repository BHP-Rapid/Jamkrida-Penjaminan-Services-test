<?php

namespace App\Services;

use App\Helpers\AesHelper;
use App\Models\PenjaminanHdr;
use App\Models\MappingValue;
use App\Models\Mitra;
use App\Models\PenjaminanLampiranDtl;
use App\Models\PenjaminanFlow;
use App\Services\CreatioService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Institution;
use App\Models\MultigunaDebitur;
use App\Models\NotifMitra;
use App\Models\PenjaminanTransaction;
use App\Models\TenantMitra;
use App\Models\TrxDebiturDefaultBase;
use App\Models\v2\TrxDebiturAjpModel;
use App\Models\v2\TrxDebiturKonstruksi;
use App\Models\v2\TrxDebiturKprModel;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class PenjaminanService
{
    public function approvePenjaminan(string $trx_no, ?string $user_id = null, ?string $user_name = null, ?string $sources = null)
    {
        DB::beginTransaction();

        try {
            Log::info('User ID for approval: ' . $user_id . ' Name: ' . $user_name);
            $penjaminan = PenjaminanHdr::where('trx_no', $trx_no)
                ->where('status_sync_creatio', 0)
                ->first();

            if (!$penjaminan) {
                throw new Exception("Penjaminan {$trx_no} not found or already synced.");
            }

            $existingProduk = MappingValue::where('key', 'jns_prod')
                ->where('value', $penjaminan->jenis_produk)
                ->select('option2')->first();

            $namaBankMitra = "";
            // dd($existingProduk);
            $dataMitra = Mitra::where('mitra_id', $penjaminan->mitra_id)->first();
            $creatioPayload = [
                "PermohonanPenjaminan" => [
                    [
                        "Name" => $penjaminan->nama,
                        "JenisNasabah" => $penjaminan->jenis_nasabah,
                        "Title" => $penjaminan->title,
                        "NIK" => $penjaminan->nik,
                        "Role" => $penjaminan->role,
                        "Birthdate" => $penjaminan->tgl_lahir,
                        "Address" => $penjaminan->alamat,
                        "Gender" => $penjaminan->jenis_kelamin,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "JenisKredit" => $penjaminan->jenis_kredit,
                        "PlafondKredit" => $penjaminan->plafon_kredit,
                        "CIF" => $penjaminan->no_rek,
                        "LoanNumber" => $penjaminan->loan_number,
                        "PenerimaPenjaminan" => $dataMitra->name_mitra,
                        "NamaTerjamin" => $penjaminan->nama,
                        "Produk" => $existingProduk ? $existingProduk->option2 : $penjaminan->jenis_produk,
                        "JenisBond" => $penjaminan->jenis_bond,
                        "SkemaPenalty" => $penjaminan->skema_penalty,
                        "JenisPersyaratan" => $penjaminan->jenis_persyaratan,
                        "Sektor" => $penjaminan->sektor,
                        "NamaPrincipal" => $penjaminan->nama_principal,
                        "NamaObligee" => $penjaminan->nama_obligee,
                        "NomorSuratPermohonan" => $penjaminan->no_surat_permohonan,
                        "JenisSuratPerjanjian" => $penjaminan->jenis_surat_perjanjian,
                        "TanggalSuratPerjanjian" => $penjaminan->tgl_surat_perjanjian,
                        "NoSuratBAST" => $penjaminan->no_surat_bast,
                        "TanggalSuratPermohonan" => $penjaminan->tgl_surat_permohonan,
                        "NoSuratPerjanjian" => $penjaminan->no_surat_perjanjian,
                        "IsBAST" => $penjaminan->is_bast,
                        "TanggalSuratBAST" => $penjaminan->tgl_surat_bast,
                        "NamaProyek" => $penjaminan->nama_proyek,
                        "NilaiProyek" => $penjaminan->nilai_proyek,
                        "NilaiBond" => $penjaminan->nilai_bond,
                        "PeriodeAwalBerlaku" => $penjaminan->period_awal,
                        "PeriodeAkhirBerlaku" => $penjaminan->period_akhir,
                        "NilaiBondPersentase" => $penjaminan->nilai_bond_persentase,
                        "JangkaWaktuHari" => $penjaminan->jangka_waktu,
                        "Provinsi" => $penjaminan->provinsi,
                        "TarifPercentage" => $penjaminan->tarif_percentage,
                        "BiayaAdministrasi" => $penjaminan->biaya_admin,
                        "IJP" => $penjaminan->ijp,
                        "BiayaMaterai" => $penjaminan->biaya_materai,
                        "Bank" => $penjaminan->bank,
                        "BankCabang" => $penjaminan->bank_cabang,
                        "PKS" => $penjaminan->pks,
                        "FeeBasePercentage" => $penjaminan->fee_base_percentage,
                        "NoSuratPermohonan" => $penjaminan->no_surat_permohonan,
                        "TeksPercentagePenjaminandiSP" => $penjaminan->text_percentage_penjaminan_sp,
                        "JenisCogar" => $penjaminan->jenis_cogar,
                        "Treaty" => $penjaminan->treaty,
                        "BookingNomorSP" => $penjaminan->booking_nomor_sp,
                        "MitraId" => $penjaminan->mitra_id,
                        "updated_by_id" => $user_id,
                        "updated_by_name" => $user_name,
                        "JenisAgunan" => $penjaminan->informasi_agunan ?? null,
                        "NilaiAgunan" => $penjaminan->agunan ?? null,
                        'updated_at' => now()
                    ]
                ]
            ];

            $svcPenjCreatio = new CreatioService();
            $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/Registrasi', $creatioPayload);

            if ($response->status() !== 200) {
                throw new Exception("Failed to register penjaminan to Core Creatio API with status: " . $response->status());
            }

            $bodyResponse = json_decode($response->body(), true);

            if ($bodyResponse['Success'] !== true) {
                throw new Exception("Failed to register penjaminan to Core Creatio API with message: " . $bodyResponse['Message']);
            }
            //$binaryLampiran = PenjaminanLampiranDtl::where('trx_no', $trx_no)->get();
            $binaryLampiran = PenjaminanLampiranDtl::where('trx_no', $trx_no)
                ->get();
            $filteredLampiran = $binaryLampiran->map(function ($item) {
                return [
                    'trx_no'     => $item->trx_no,
                    'lampiran_id' => $item->lampiran_id,
                    'file_name'  => $item->file_name,
                    'mime_type'  => $item->mime_type,
                ];
            });
            // dd($filteredLampiran);
            LOG::info("check response {$filteredLampiran}");
            $lampiranCodeList = $binaryLampiran->pluck('lampiran_id')->all();
            $namaJenisDokumenCreatio = MappingValue::where('key', 'lampiran')
                ->whereIn('value', $lampiranCodeList)
                ->select('value', 'option3')
                ->get();

            $listPerorangan = ['ktp', 'npywp'];
            $listSyaratUmum = ['app', 'npwp', 'ppp', 'siujk'];
            $listSyaratKhusus = ['nib', 'rdpj'];
            foreach ($binaryLampiran as $bin) {
                // $key = base64_decode(config('services.secure.key'));
                $fileName = $bin->file_name;
                $dataBase64 = $bin->data_base64;
                $lampiranId = $bin->lampiran_id;
                $mimeType = $bin->mime_type;
                $namaJenis = "";
                $jenisDokumenById = $namaJenisDokumenCreatio->firstWhere('value', strtolower($lampiranId));
                LOG::info("check jenis dokumen {$jenisDokumenById}");
                if ($jenisDokumenById) {
                    $namaJenis = $jenisDokumenById->option3;
                }
                // check if dokumen is in perorangan
                if (in_array(strtolower($bin->lampiran_id), $listPerorangan)) {
                    $mime = $mimeType ?: 'application/octet-stream';
                    $finalBase64 = $dataBase64;
                    $payloadDocument = [
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" =>  $fileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Perorangan",
                        "DataBase64" => $finalBase64
                    ];
                    $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    LOG::info("check inside looping {$response} {$namaJenis} {$fileName}");
                    if ($response->status() !== 200) {
                        throw new Exception("Failed to Send Document penjaminan " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    }
                    $bodyResponse = json_decode($response->body(), true);
                    if ($bodyResponse['Success'] !== true) {
                        throw new Exception("Failed to Send penjaminan " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    }
                }

                if (in_array(strtolower($bin->lampiran_id), $listSyaratUmum)) {
                    $mime = $mimeType ?: 'application/octet-stream';
                    $finalBase64 = $dataBase64;
                    $payloadDocument = [
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" =>  $fileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Syarat Umum",
                        "DataBase64" => $finalBase64
                    ];
                    $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    LOG::info("check inside looping {$response} {$namaJenis} {$fileName}");
                    if ($response->status() !== 200) {
                        throw new Exception("Failed to Send Document penjaminan " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    }
                    $bodyResponse = json_decode($response->body(), true);
                    if ($bodyResponse['Success'] !== true) {
                        throw new Exception("Failed to Send penjaminan " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    }
                }

                // check if dokumen is in syarat khusus
                if (in_array(strtolower($bin->lampiran_id), $listSyaratKhusus)) {
                    $finalBase64 = $dataBase64;
                    $payloadDocument = [
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" =>  $fileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Syarat Khusus",
                        "DataBase64" => $finalBase64
                    ];
                    $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    LOG::info("check inside looping {$response} {$namaJenis} {$fileName}");
                    if ($response->status() !== 200) {
                        throw new Exception("Failed to Send Document penjaminan " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    }
                    $bodyResponse = json_decode($response->body(), true);
                    if ($bodyResponse['Success'] !== true) {
                        throw new Exception("Failed to Send penjaminan " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    }
                }
            }

            // $iterateUpload = 0;
            // foreach ($binaryLampiran as $bin) {
            //     $iterateUpload++;
            //     $subUrl = '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan';
            //     $paramTrx = '?NomorPermohonan=' . $trxNo;
            //     $paramJenisDokumen = '&JenisDokumen=Profil Perusahaan Principal';
            //     $paramTipeDokumen = '&TipeDokumen=Syarat Umum';
            //     $paramNamaDokumen = '&NamaDokumen=' . $bin['name'];
            //     $urlWithParams = $subUrl . $paramTrx . $paramJenisDokumen . $paramTipeDokumen . $paramNamaDokumen;
            //     $uploadRes = $svcPenjCreatio->request('binary', $urlWithParams, [], [], 1, $bin['data'], $bin['type']);

            //     if ($uploadRes->status() !== 200) {
            //         throw new Exception("Failed to upload document " . $iterateUpload . " to Core Creatio API with status: " . $uploadRes->status());
            //     }

            //     $uploadResBody = json_decode($uploadRes->body(), true);

            //     if ($uploadResBody['Success'] !== true) {
            //         throw new Exception("Failed to upload document " . $iterateUpload . " to Core Creatio API with message: " . $uploadResBody['Message']);
            //     }
            // }

            $notifCreatioPayload = [
                "Title" => "Mitra Portal Notification",
                "Subject" => "Register Penjaminan Success",
                // "Contact" => $request->nama
                "Contact" => "Supervisor"
            ];

            $notifRes = $svcPenjCreatio->request('post', '/0/rest/Notification/SendNotification', $notifCreatioPayload);

            // if ($notifRes->status() !== 200) {
            //     throw new Exception("Failed to send notification to Core Creatio API with status: " . $notifRes->status());
            // }

            $notifResBody = json_decode($notifRes->body(), true);

            // if ($notifResBody['Success'] !== true) {
            //     throw new Exception("Failed to send notification to Core Creatio API with message: " . $notifResBody['Message']);
            // }


            // Kirim SMS setelah berhasil simpan
            $noTelp = $penjaminan->no_telp;

            // Format nomor telepon ke internasional (Indonesia)
            if (str_starts_with($noTelp, '0')) {
                $noTelp = '62' . substr($noTelp, 1); // contoh: 08123... → 628123...
            }
            LOG::info("reaching final update for penjaminan {$trx_no} with phone number {$noTelp}");
            // Final update
            PenjaminanHdr::where('trx_no', $trx_no)->update([
                'status_sync_creatio' => 1,
                'trx_status' => $penjaminan->plafon_kredit >= $penjaminan->nilai_proyek ? 'WFP' : 'S',
                'updated_by_id' => $user_id,
                'updated_by_name' => $user_name,
                'updated_at' => now(),
            ]);

            PenjaminanFlow::insert([
                'trx_no' => $trx_no,
                'trx_status' => 'S',
                'created_at' => now(),
                'updated_at' => now(),
                'created_by_id' => auth('sanctum')->user()->user_id,
                'created_by_name' => auth('sanctum')->user()->name,
                'status' => true
            ]);

            NotifMitra::create([
                'mitra_user_id' => auth('sanctum')->user()->user_id,
                'title' => "Mitra Portal - Klaim",
                'message' => "Status claim dengan nomor " . $trx_no . " menjadi " . "Submitted",
            ]);

            if ($penjaminan->plafon_kredit >= $penjaminan->nilai_proyek) {
                PenjaminanFlow::insert([
                    'trx_no' => $trx_no,
                    'trx_status' => 'WFP',
                    'created_at' => now(),
                    'updated_at' => now(),
                    'created_by_id' => auth('sanctum')->user()->user_id,
                    'created_by_name' => auth('sanctum')->user()->name
                ]);

                NotifMitra::create([
                    'mitra_user_id' => auth('sanctum')->user()->user_id,
                    'title' => "Mitra Portal - Klaim",
                    'message' => "Status claim dengan nomor " . $trx_no . " menjadi " . "Waiting For Payment",
                ]);
            }

            DB::commit();
            Log::info("Penjaminan {$trx_no} approved successfully.");
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Error approving penjaminan {$trx_no}: {$e->getMessage()}");
            throw $e;
        }
    }

    public function approvePenjaminanMultigunaNew(string $trx_no, ?string $user_id = null, ?string $user_name = null, ?string $sources = null)
    {
        ini_set('max_execution_time', 0);
        try {
            DB::beginTransaction();
            Log::info('User ID for approval: ' . $user_id . ' Name: ' . $user_name);
            $penjaminan = PenjaminanTransaction::join('multiguna_transaction as mt', 'transaction_penjaminan_header.trx_no', '=', 'mt.trx_no')
                ->where('transaction_penjaminan_header.trx_no', $trx_no)
                ->where('transaction_penjaminan_header.status_sync_creatio', 0)
                ->first();
            if (!$penjaminan) {
                throw new Exception("Penjaminan {$trx_no} not found or already synced.");
            }

            $mitra_id = auth('sanctum')->user()->mitra_id;

            $mitra = TenantMitra::where('mitra_id', $mitra_id)
                ->select('alias', 'is_syariah', 'is_conventional')
                ->first();

            Log::info('regist multiguna', [
                'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                'time' => now()->toDateTimeString(),
            ]);
            // temporarily remark regist penjaminan ot core
            $debiturs = MultigunaDebitur::where('multiguna_trx_id', $penjaminan->id_multiguna)->get();
            // dd($debiturs);
            $allCAC = $debiturs->every(fn($d) => ($d->jenis_penjaminan ?? null) === 'CAC');
            $allCBC = $debiturs->every(fn($d) => ($d->jenis_penjaminan ?? null) === 'CBC');
            // dd($allCAC);
            $finalTrxStatus = null;
            if ((!$allCAC && !$allCBC) || ($allCAC)) {
                $finalTrxStatus = 'WFP';
            } elseif ($allCBC) {
                $finalTrxStatus = 'S';
            }
            // dd($finalTrxStatus);
            $institutionIds = $debiturs->pluck('institution_id')->filter()->unique()->values();
            $institutionsById = Institution::whereIn('institution_id', $institutionIds)
                ->get()
                ->keyBy('institution_id');
            $debitursArray = $debiturs->map(function ($d) use ($institutionsById) {
                $arr = $d->toArray();
                $arr['nik'] = $d->nik;
                $arr['birth_date'] = $institutionsById[$d->institution_id]->birth_date ?? null;
                $arr['gender'] = $institutionsById[$d->institution_id]->gender ?? null;
                return $arr;
            })->values()->all();
            // // dd($debitursArray);
            // $existingProduk = MappingValue::where('key', 'jns_prod')
            //     ->where('value', $penjaminan->jenis_prodproduct)
            //     ->select('option2')->first();
            $dataMitra = Mitra::where('mitra_id', $penjaminan->mitra_id)->first();
            $nowJakarta = Carbon::now('Asia/Jakarta');
            // $creatioPayload = [
            //     "PermohonanPenjaminanMultiguna" => [
            //         [
            //             "CaraBayar" => $penjaminan->sp_split == true ? 'Installment' : 'FullPayment',
            //             "PKS" => $penjaminan->pks_number ?? null,
            //             "NilaiProyek" => 0,
            //             "TanggalSuratPermohonan" => $penjaminan->tanggal_surat_permohonan ?? null,
            //             "NomorSuratPermohonan" => $penjaminan->no_surat_permohonan ?? null,
            //             "PeriodeAwalBerlaku" => null,
            //             "PeriodeAkhirBerlaku" => null,
            //             "TarifPercentage" => $penjaminan->fee_base_number ?? null,
            //             "IJP" => null,
            //             "NomorPermohonan" => $penjaminan->no_surat_permohonan ?? null,
            //             "BankCabang" => $penjaminan->bank_code . ' - ' . $penjaminan->bank_name ?? null,
            //             "FeeBasePercentage" => $penjaminan->fee_base_percentage ?? null,
            //             "TeksPercentagePenjaminandiSP" => $penjaminan->text_certified ?? null,
            //             "MitraId" => 'MDR',
            //             "BookingNomorSP" => null,
            //             "LoanNumber" => '1231203',
            //             "JenisAgunan" => 'Konvensional',
            //             "NilaiAgunan" => 10000000,
            //             "ListDebitur" => collect($debitursArray)->map(function (array $d) use ($nowJakarta) {
            //                 return [
            //                     "Name" => $d['debitur_name'] ?? null,
            //                     "Nik" => $d['nik'] ?? null,
            //                     "NamaMakhfulAnhu" => $d['jenis_makful_anhu'] ?? null,
            //                     "TanggalLahir" => $d['birth_date'] ?? null,
            //                     "NilaiKafalah" => $d['nilai_kafalah'] ?? 0,
            //                     "TanggalRealisasi" => $d['tanggal_realisasi'] ?? null,
            //                     "NilaiAgunan" => $d['nilai_agunan'] ?? 0,
            //                     "JenisAgunan" => $d['jenis_agunan'] ?? null,
            //                     "JenisMakhfulAnhu" => $d['jenis_makful_anhu'] ?? null,
            //                     "InstansiPekerjaanTerjamin" => $d['institution_name'] ?? $d['instansi_pekerjaan_terjamin'] ?? null,
            //                     "JwBulan" => $d['jw_bulan'] ?? 0,
            //                     "JenisKelamin" => $d['gender'] == "L" ? "Laki-Laki" : "Perempuan",
            //                     "MarginBagiHasilUjrahTahun" => $d['margin'] ?? 0,
            //                     "JumlahDana" => $d['plafond_pembiayaan'] ?? 0,
            //                     "PlafonPembiayaan" => $d['plafond_pembiayaan'] ?? ($d['plafond_pembiayaan_rp'] ?? 0),
            //                     "LoanNumber" => $d['loan_number'] ?? null,
            //                     "TanggalJatuhTempo" => $d['tanggal_jatuh_tempo'] ?? null,
            //                     "PenggunaanPembiayaan" => $d['penggunaan_pembiayaan'] ?? null,
            //                     "TenagaKerja" => $d['tenaga_kerja'] ?? null,
            //                     "Tanggal" => $nowJakarta->toDateString(),
            //                     "Tenor" => $d['jw_bulan'] ?? 0,
            //                 ];
            //             })->values()->all(),
            //         ]
            //     ]
            // ];

            // dummy response from core regist for testing
            // $dummyRegistResponse = [
            //     'Data'=> [
            //         [
            //             'Detail'=> [
            //                 [
            //                     'Debitur'=> 'Doricky',
            //                     'NIK'=> '1231231231231231',
            //                     'NomorPermohonanDebitur'=> 'Testing002-1'
            //                 ],
            //                 [
            //                     'Debitur'=> 'Budi',
            //                     'NIK'=> '2387654873458734',
            //                     'NomorPermohonanDebitur'=> 'Testing002-2'
            //                 ],
            //                 [
            //                     'Debitur'=> 'Fransetya Alfi',
            //                     'NIK'=> '11002328132912301',
            //                     'NomorPermohonanDebitur'=> 'Testing002-3'
            //                 ],
            //                 [
            //                     'Debitur'=> 'Fuad Santoso',
            //                     'NIK'=> '3276015401012301',
            //                     'NomorPermohonanDebitur'=> 'Testing002-4'
            //                 ]
            //             ],
            //             'NomorPermohonan'=> 'Testing002'
            //         ]
            //     ],
            //     'Message'=> 'Permohonan Penjaminan Succesfully Created.',
            //     'Success'=> true
            // ];

            $creatioPayload = [
                "PermohonanPenjaminanMultiguna" => [
                    [
                        "CaraBayar" => $penjaminan->sp_split == true ? 'Installment' : 'Full Payment',
                        "PKS" => $penjaminan->pks_number ?? '0018/JJ-1/KBP.00.06/PIK/2025',
                        "TanggalSuratPermohonan" => $penjaminan->tanggal_surat_permohonan ?? '2025-10-27',
                        "NomorSuratPermohonan" => $penjaminan->no_surat_permohonan ?? '1000023-Testing003',
                        "MitraId" => 'MDR',
                        "TarifPercentage" => (int)  $penjaminan->fee_base_number ?? 2.00,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan ?? 'Testing003',
                        "BankCabang" => $penjaminan->bank_code . ' - ' . $penjaminan->bank_name ?? 'BANK MANDIRI - Mandiri Sudirman',
                        "FeeBasePercentage" => (int)  $penjaminan->fee_base_percentage ?? 10.00,
                        "TeksPercentagePenjaminandiSP" => (int) $penjaminan->text_certified ?? 70.00,
                        "IsConven" => (bool)$mitra->is_conventional  ? true : false,
                        "ListDebitur" => collect($debitursArray)->map(function (array $d) use ($nowJakarta) {
                            return [
                                "Name" => $d['debitur_name'] ?? 'Doricky',
                                "Nik" => $d['nik'] ?? '1231231231231231',
                                "NamaMakhfulAnhu" => $d['jenis_makful_anhu'] ?? 'Testing',
                                "TanggalLahir" => $d['birth_date'] ?? '1995-08-29',
                                "NilaiKafalah" => (int)  $d['nilai_kafalah'] ?? 70000000,
                                "TanggalRealisasi" => $d['tanggal_realisasi'] ?? '2028-08-29',
                                "NilaiAgunan" => (int) $d['nilai_agunan'] ?? 10000000,
                                "JenisAgunan" => $d['jenis_agunan'] ?? 'IJK',
                                "JenisMakhfulAnhu" => $d['jenis_makful_anhu'] ?? 'Testing',
                                "InstansiPekerjaanTerjamin" => $d['institution_name'] ?? 'Testing',
                                "JwBulan" => (int) $d['jw_bulan'] ?? 12,
                                "JenisKelamin" => (int) $d['gender'] == "L" ? "Laki-Laki" : "Perempuan",
                                "MarginBagiHasilUjrahTahun" => (int) $d['margin'] ?? 120000,
                                "JumlahDana" => (int) $d['plafond_pembiayaan'] ?? 100000,
                                "PlafonPembiayaan" => (int) $d['plafond_pembiayaan'] ?? 10000000,
                                "LoanNumber" => $d['loan_number'] ?? '1231203',
                                "TanggalJatuhTempo" => $d['tanggal_jatuh_tempo'] ?? '2028-08-29',
                                "PenggunaanPembiayaan" => $d['penggunaan_pembiayaan'] ?? 'Testing',
                                "TenagaKerja" => $d['tenaga_kerja'] ?? 'Testng',
                                "Tanggal" => $nowJakarta->toDateString(),
                                "Tenor" => (int) $d['jw_bulan'] ?? 12,
                            ];
                        })->values()->all(),
                    ]
                ]
            ];
            $svcPenjCreatio = new CreatioService();
            $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/RegistrasiMultiguna', $creatioPayload);
            if ($response->status() !== 200) {
                throw new Exception("Failed to register Penjaminan Multiguna to Core Creatio API with status: " . $response->status());
            }

            $bodyResponse = json_decode($response->body(), true);
            if ($bodyResponse['Success'] !== true) {
                throw new Exception("Failed to register Penjaminan Multiguna to Core Creatio API with message: " . $bodyResponse['Message']);
            }
            // if(!is_array($bodyResponse['Data']) || empty($bodyResponse['Data']))
            // {
            //     throw new Exception('Failed to get response from register Penjaminan Multiguna to Core API: empty or unreadable Data from response.');
            // }
            // $responsePayload = $bodyResponse['Data'][0];
            // if(!is_array($responsePayload["Detail"]))
            // {
            //     throw new Exception('Failed to get response from register Penjaminan Multiguna to Core API: unreadable Detail array from response.');
            // }
            // test using dummy response first
            // if($dummyRegistResponse['Success'] !== true) {
            //     throw new Exception("Failed to register Penjaminan Multiguna to Core Creatio API with message: " . $bodyResponse['Message']);
            // }
            // if(!is_array($dummyRegistResponse['Data']) || empty($dummyRegistResponse['Data']))
            // {
            //     throw new Exception('Failed to get response from register Penjaminan Multiguna to Core API: empty or unreadable Data from response.');
            // }
            // $responsePayload = $dummyRegistResponse['Data'][0];
            // if(!is_array($responsePayload["Detail"]))
            // {
            //     throw new Exception('Failed to get response from register Penjaminan Multiguna to Core API: unreadable Detail array from response.');
            // }

            // $key = base64_decode(config('services.secure.key'));
            // $debiturQueryData = MultigunaDebitur::where('multiguna_trx_id', $penjaminan->id_multiguna)
            //     ->select('id_trx_debitur', 'no_sp_detail', 'debitur_name', 'debitur_address', 'loan_number', 'nik')
            //     ->get()->map(function($query) use ($key) {
            //         $nikDecrypt = AesHelper::decrypt($query->nik, $key);
            //         $result = [
            //             'id_trx_debitur' => $query->id_trx_debitur,
            //             'no_sp_detail' => $query->no_sp_detail,
            //             'debitur_name' => $query->debitur_name,
            //             'debitur_address' => $query->debitur_address,
            //             'loan_number' => $query->loan_number,
            //             'nik' => $nikDecrypt
            //         ];
            //         return $result;
            //     });
            // $queryCollectArr = collect($debiturQueryData)->toArray();

            Log::info('END regist multiguna', [
                'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                'time' => now()->toDateTimeString(),
            ]);
            // (END) temporarily remark regist penjaminan ot core

            // 1. temporarily remarked (send lampiran to core creatio)
            $binaryLampiran = PenjaminanLampiranDtl::where('trx_no', $trx_no)
                ->select(
                    'trx_no',
                    'lampiran_id',
                    'file_info',
                    'mime_type'
                )->cursor();
            $lampiranCodeList = array_unique($binaryLampiran->pluck('lampiran_id')->toArray());
            $lampiranJenisMapping = MappingValue::where('key', 'lampiran')
                ->whereIn('value', $lampiranCodeList)
                ->select('value', 'option3')
                ->get();

            $listPerorangan = ['ktp', 'npywp'];
            $listSyaratUmum = ['app', 'npwp', 'ppp', 'siujk'];
            $listSyaratKhusus = ['nib', 'rdpj'];
            // (END) 1. temporarily remarked (send lampiran to core creatio)

            // $mapJenisLampiran = [
            //     [
            //         "type" => "Perorangan",
            //         "list" => $listPerorangan
            //     ],
            //     [
            //         "type" => "Syarat Umum",
            //         "list" => $listSyaratUmum
            //     ],
            //     [
            //         "type" => "Syarat Khusus",
            //         "list" => $listSyaratKhusus
            //     ]
            // ];
            // dd($binaryLampiran);


            Log::info('iterate lampiran multiguna', [
                'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                'time' => now()->toDateTimeString(),
            ]);
            // 2. temporarily remarked (send lampiran to core creatio)
            foreach ($binaryLampiran as $bin) {
                Log::info('Get NIK and base64 lampiran ' .  $bin->lampiran_id, [
                    'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                    'time' => now()->toDateTimeString(),
                ]);
                $binFileNameParse = explode('/', $bin->file_info);
                $debiturFileName = $binFileNameParse[count($binFileNameParse) - 1];
                $fileNameParse = explode('-', $debiturFileName);
                $fileNik = $fileNameParse[0];
                $binS3Content = Storage::disk('s3')->get($bin->file_info);
                $binS3Base64 = base64_encode($binS3Content);

                $jenisByLampiranId = $lampiranJenisMapping
                    ->firstWhere('value', strtolower($bin->lampiran_id));
                $namaJenis = $jenisByLampiranId ? $jenisByLampiranId->option3 : "";

                LOG::info("check jenis dokumen {$jenisByLampiranId}");

                // check if lampiran id is in list of lampiran perorangan
                if (in_array(strtolower($bin->lampiran_id), $listPerorangan)) {
                    $payloadDocument = [
                        "NIK" => $fileNik,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Perorangan",
                        "DataBase64" => $binS3Base64
                    ];
                    // dd($payloadDocument);
                    Log::info('Sending lampiran ' .  $namaJenis . ' Perorangan to Creatio', [
                        'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                        'time' => now()->toDateTimeString(),
                    ]);
                    $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    // dd($response);
                    LOG::info("check inside looping {$response} {$namaJenis} {$debiturFileName}");
                    if ($response->status() !== 200) {
                        throw new Exception("Failed to Send Document penjaminan Perorangan " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    }
                    $bodyResponse = json_decode($response->body(), true);
                    // dd($bodyResponse);
                    if ($bodyResponse['Success'] !== true) {
                        throw new Exception("Failed to Send penjaminan Perorangan " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    }
                }

                // check if lampiran id is in list of lampiran syarat umum
                if (in_array(strtolower($bin->lampiran_id), $listSyaratUmum)) {
                    $payloadDocument = [
                        "NIK" => $fileNik,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Syarat Umum",
                        "DataBase64" => $binS3Base64
                    ];
                    Log::info('Sending lampiran '  .  $namaJenis . ' Syarat Umum to Creatio', [
                        'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                        'time' => now()->toDateTimeString(),
                    ]);
                    $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    LOG::info("check inside looping {$response} {$namaJenis} {$debiturFileName}");
                    if ($response->status() !== 200) {
                        throw new Exception("Failed to Send Document penjaminan Syarat Umum " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    }
                    $bodyResponse = json_decode($response->body(), true);
                    if ($bodyResponse['Success'] !== true) {
                        throw new Exception("Failed to Send penjaminan Syarat Umum " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    }
                }

                // check if lampiran id is in list of lampiran syarat khusus
                if (in_array(strtolower($bin->lampiran_id), $listSyaratKhusus)) {
                    $payloadDocument = [
                        "NIK" => $fileNik,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Syarat Khusus",
                        "DataBase64" => $binS3Base64
                    ];
                    Log::info('Sending lampiran ' .  $namaJenis . ' Syarat Khusus to Creatio', [
                        'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                        'time' => now()->toDateTimeString(),
                    ]);
                    $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    LOG::info("check inside looping {$response} {$namaJenis} {$debiturFileName}");
                    if ($response->status() !== 200) {
                        throw new Exception("Failed to Send Document penjaminan Syarat Khusus " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    }
                    $bodyResponse = json_decode($response->body(), true);
                    if ($bodyResponse['Success'] !== true) {
                        throw new Exception("Failed to Send penjaminan Syarat Khusus " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    }
                }
            }
            Log::info('END iterate lampiran multiguna', [
                'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                'time' => now()->toDateTimeString(),
            ]);
            // dd("upload done");
            // (END) 2. temporarily remarked (send lampiran to core creatio)

            // temporarily remark send notif to core
            $notifCreatioPayload = [
                "Title" => "Mitra Portal Notification",
                "Subject" => "Register Penjaminan Success",
                // "Contact" => $request->nama
                "Contact" => "Supervisor"
            ];
            $notifRes = $svcPenjCreatio->request('post', '/0/rest/Notification/SendNotification', $notifCreatioPayload);
            $notifResBody = json_decode($notifRes->body(), true);
            // (END) temporarily remark send notif to core

            // $noTelp = $penjaminan->no_telp;
            // if (str_starts_with($noTelp, '0')) {
            //     $noTelp = '62' . substr($noTelp, 1); 
            // }
            // LOG::info("reaching final update for penjaminan {$trx_no} with phone number {$noTelp}");

            PenjaminanTransaction::where('trx_no', $trx_no)->update([
                'status_sync_creatio' => 1,
                'trx_status' => $finalTrxStatus,
                'updated_by_id' => $user_id,
                'updated_by_name' => $user_name,
                'updated_at' => $nowJakarta,
            ]);

            PenjaminanFlow::insert([
                'trx_no' => $trx_no,
                'trx_status' => $finalTrxStatus,
                'created_at' => $nowJakarta,
                'created_by_id' => auth('sanctum')->user()->user_id,
                'created_by_name' => auth('sanctum')->user()->name,
                'status' => true
            ]);

            NotifMitra::create([
                'mitra_user_id' => auth('sanctum')->user()->user_id,
                'title' => "Mitra Portal - Penjaminan Multiguna",
                'message' => "Status penjaminan multiguna dengan nomor " . $trx_no . " menjadi " . "Submitted",
            ]);

            DB::commit();
            Log::info("Penjaminan Multiguna {$trx_no} approved successfully.");
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Error approving penjaminan multi {$trx_no}: {$e->getMessage()}");
            throw $e;
        }
    }

    public function approvePenjaminanKMK(string $trx_no, ?string $user_id = null, ?string $user_name = null, ?string $sources = null)
    {
        ini_set('max_execution_time', 0);
        try {
            DB::beginTransaction();
            Log::info('User ID for approval: ' . $user_id . ' Name: ' . $user_name);
            $penjaminan = PenjaminanTransaction::join('multiguna_trx_kredit_mikro_kecil as mtkmk', 'transaction_penjaminan_header.trx_no', '=', 'mtkmk.trx_no')
                ->where('transaction_penjaminan_header.trx_no', $trx_no)
                ->where('transaction_penjaminan_header.status_sync_creatio', 0)
                ->first();
            if (!$penjaminan) {
                throw new Exception("Penjaminan {$trx_no} not found or already synced.");
            }
            Log::info('regist multiguna', [
                'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                'time' => now()->toDateTimeString(),
            ]);
            // temporarily remark regist penjaminan ot core
            $debiturs = TrxDebiturDefaultBase::where('kredit_mikro_trx_id', $penjaminan->id_multiguna_kredit_mikro_kecil)->get();
            $allCAC = $debiturs->every(fn($d) => ($d->jenis_penjaminan ?? null) === 'CAC');
            $allCBC = $debiturs->every(fn($d) => ($d->jenis_penjaminan ?? null) === 'CBC');
            $finalTrxStatus = null;
            if ((!$allCAC && !$allCBC) || ($allCAC)) {
                $finalTrxStatus = 'WFP';
            } elseif ($allCBC) {
                $finalTrxStatus = 'S';
            }
            $institutionIds = $debiturs->pluck('institution_id')->filter()->unique()->values();
            $institutionsById = Institution::whereIn('institution_id', $institutionIds)
                ->get()
                ->keyBy('institution_id');
            $debitursArray = $debiturs->map(function ($d) use ($institutionsById) {
                $arr = $d->toArray();
                $arr['nik'] = $d->nik;
                $arr['birth_date'] = $institutionsById[$d->institution_id]->birth_date ?? null;
                $arr['gender'] = $institutionsById[$d->institution_id]->gender ?? null;
                return $arr;
            })->values()->all();

            $dataMitra = Mitra::where('mitra_id', $penjaminan->mitra_id)->first();
            $nowJakarta = Carbon::now('Asia/Jakarta');
            // $creatioPayload = [
            //     "PermohonanPenjaminanMultiguna" => [
            //         [
            //             "CaraBayar" => $penjaminan->sp_split == true ? 'Installment' : 'Full Payment',
            //             "PKS" => $penjaminan->pks_number ?? '0018/JJ-1/KBP.00.06/PIK/2025',
            //             "TanggalSuratPermohonan" => $penjaminan->tanggal_surat_permohonan ?? '2025-10-27',
            //             "NomorSuratPermohonan" => $penjaminan->no_surat_permohonan ?? '1000023-Testing003',
            //             "MitraId" => 'MDR',
            //             "TarifPercentage" => (int)  $penjaminan->fee_base_number ?? 2.00,
            //             "NomorPermohonan" => $penjaminan->no_surat_permohonan ?? 'Testing003',
            //             "BankCabang" => $penjaminan->bank_code . ' - ' . $penjaminan->bank_name ?? 'BANK MANDIRI - Mandiri Sudirman',
            //             "FeeBasePercentage" => (int)  $penjaminan->fee_base_percentage ?? 10.00,
            //             "TeksPercentagePenjaminandiSP" => (int) $penjaminan->text_certified ?? 70.00,
            //             "ListDebitur" => collect($debitursArray)->map(function (array $d) use ($nowJakarta) {
            //                 return [
            //                     "Name" => $d['debitur_name'] ?? 'Doricky',
            //                     "Nik" => $d['nik'] ?? '1231231231231231',
            //                     "NamaMakhfulAnhu" => $d['jenis_makful_anhu'] ?? 'Testing',
            //                     "TanggalLahir" => $d['birth_date'] ?? '1995-08-29',
            //                     "NilaiKafalah" => (int)  $d['nilai_kafalah'] ?? 70000000,
            //                     "TanggalRealisasi" => $d['tanggal_realisasi'] ?? '2028-08-29',
            //                     "NilaiAgunan" => (int) $d['nilai_agunan'] ?? 10000000,
            //                     "JenisAgunan" => $d['jenis_agunan'] ?? 'IJK',
            //                     "JenisMakhfulAnhu" => $d['jenis_makful_anhu'] ?? 'Testing',
            //                     "InstansiPekerjaanTerjamin" => $d['institution_name'] ?? 'Testing',
            //                     "JwBulan" => (int) $d['jw_bulan'] ?? 12,
            //                     "JenisKelamin" => (int) $d['gender'] == "L" ? "Laki-Laki" : "Perempuan",
            //                     "MarginBagiHasilUjrahTahun" => (int) $d['margin'] ?? 120000,
            //                     "JumlahDana" => (int) $d['plafond_pembiayaan'] ?? 100000,
            //                     "PlafonPembiayaan" => (int) $d['plafond_pembiayaan'] ?? 10000000,
            //                     "LoanNumber" => $d['loan_number'] ?? '1231203',
            //                     "TanggalJatuhTempo" => $d['tanggal_jatuh_tempo'] ?? '2028-08-29',
            //                     "PenggunaanPembiayaan" => $d['penggunaan_pembiayaan'] ?? 'Testing',
            //                     "TenagaKerja" => $d['tenaga_kerja'] ?? 'Testng',
            //                     "Tanggal" => $nowJakarta->toDateString(),
            //                     "Tenor" => (int) $d['jw_bulan'] ?? 12,
            //                 ];
            //             })->values()->all(),
            //         ]
            //     ]
            // ];
            // $svcPenjCreatio = new CreatioService();
            // $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/RegistrasiMultiguna', $creatioPayload);
            // // dd($response);
            // if ($response->status() !== 200) {
            //     throw new Exception("Failed to register Penjaminan Multiguna to Core Creatio API with status: " . $response->status());
            // }

            // $bodyResponse = json_decode($response->body(), true);
            // if ($bodyResponse['Success'] !== true) {
            //     throw new Exception("Failed to register Penjaminan Multiguna to Core Creatio API with message: " . $bodyResponse['Message']);
            // }
            // if(!is_array($bodyResponse['Data']) || empty($bodyResponse['Data']))
            // {
            //     throw new Exception('Failed to get response from register Penjaminan Multiguna to Core API: empty or unreadable Data from response.');
            // }
            // $responsePayload = $bodyResponse['Data'][0];
            // if(!is_array($responsePayload["Detail"]))
            // {
            //     throw new Exception('Failed to get response from register Penjaminan Multiguna to Core API: unreadable Detail array from response.');
            // }
            // test using dummy response first
            // if($dummyRegistResponse['Success'] !== true) {
            //     throw new Exception("Failed to register Penjaminan Multiguna to Core Creatio API with message: " . $bodyResponse['Message']);
            // }
            // if(!is_array($dummyRegistResponse['Data']) || empty($dummyRegistResponse['Data']))
            // {
            //     throw new Exception('Failed to get response from register Penjaminan Multiguna to Core API: empty or unreadable Data from response.');
            // }
            // $responsePayload = $dummyRegistResponse['Data'][0];
            // if(!is_array($responsePayload["Detail"]))
            // {
            //     throw new Exception('Failed to get response from register Penjaminan Multiguna to Core API: unreadable Detail array from response.');
            // }

            // $key = base64_decode(config('services.secure.key'));
            // $debiturQueryData = MultigunaDebitur::where('multiguna_trx_id', $penjaminan->id_multiguna)
            //     ->select('id_trx_debitur', 'no_sp_detail', 'debitur_name', 'debitur_address', 'loan_number', 'nik')
            //     ->get()->map(function($query) use ($key) {
            //         $nikDecrypt = AesHelper::decrypt($query->nik, $key);
            //         $result = [
            //             'id_trx_debitur' => $query->id_trx_debitur,
            //             'no_sp_detail' => $query->no_sp_detail,
            //             'debitur_name' => $query->debitur_name,
            //             'debitur_address' => $query->debitur_address,
            //             'loan_number' => $query->loan_number,
            //             'nik' => $nikDecrypt
            //         ];
            //         return $result;
            //     });
            // $queryCollectArr = collect($debiturQueryData)->toArray();

            Log::info('END regist multiguna', [
                'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                'time' => now()->toDateTimeString(),
            ]);
            // (END) temporarily remark regist penjaminan ot core

            // 1. temporarily remarked (send lampiran to core creatio)
            $binaryLampiran = PenjaminanLampiranDtl::where('trx_no', $trx_no)
                ->select(
                    'trx_no',
                    'lampiran_id',
                    'file_info',
                    'mime_type'
                )->cursor();
            $lampiranCodeList = array_unique($binaryLampiran->pluck('lampiran_id')->toArray());
            $lampiranJenisMapping = MappingValue::where('key', 'lampiran')
                ->whereIn('value', $lampiranCodeList)
                ->select('value', 'option3')
                ->get();

            $listPerorangan = ['ktp', 'npywp'];
            $listSyaratUmum = ['app', 'npwp', 'ppp', 'siujk'];
            $listSyaratKhusus = ['nib', 'rdpj'];
            // (END) 1. temporarily remarked (send lampiran to core creatio)

            // $mapJenisLampiran = [
            //     [
            //         "type" => "Perorangan",
            //         "list" => $listPerorangan
            //     ],
            //     [
            //         "type" => "Syarat Umum",
            //         "list" => $listSyaratUmum
            //     ],
            //     [
            //         "type" => "Syarat Khusus",
            //         "list" => $listSyaratKhusus
            //     ]
            // ];
            // dd($binaryLampiran);


            Log::info('iterate lampiran multiguna', [
                'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                'time' => now()->toDateTimeString(),
            ]);
            // 2. temporarily remarked (send lampiran to core creatio)
            foreach ($binaryLampiran as $bin) {
                Log::info('Get NIK and base64 lampiran ' .  $bin->lampiran_id, [
                    'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                    'time' => now()->toDateTimeString(),
                ]);
                $binFileNameParse = explode('/', $bin->file_info);
                $debiturFileName = $binFileNameParse[count($binFileNameParse) - 1];
                $fileNameParse = explode('-', $debiturFileName);
                $fileNik = $fileNameParse[0];
                $binS3Content = Storage::disk('s3')->get($bin->file_info);
                $binS3Base64 = base64_encode($binS3Content);
                $jenisByLampiranId = $lampiranJenisMapping
                    ->firstWhere('value', strtolower($bin->lampiran_id));
                $namaJenis = $jenisByLampiranId ? $jenisByLampiranId->option3 : "";
                LOG::info("check jenis dokumen {$jenisByLampiranId}");
                // check if lampiran id is in list of lampiran perorangan
                if (in_array(strtolower($bin->lampiran_id), $listPerorangan)) {
                    $payloadDocument = [
                        "NIK" => $fileNik,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Perorangan",
                        "DataBase64" => $binS3Base64
                    ];
                    Log::info('Sending lampiran ' .  $namaJenis . ' Perorangan to Creatio', [
                        'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                        'time' => now()->toDateTimeString(),
                    ]);
                    // $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    // LOG::info("check inside looping {$response} {$namaJenis} {$debiturFileName}");
                    // if ($response->status() !== 200) {
                    //     throw new Exception("Failed to Send Document penjaminan Perorangan " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    // }
                    // $bodyResponse = json_decode($response->body(), true);
                    // if ($bodyResponse['Success'] !== true) {
                    //     throw new Exception("Failed to Send penjaminan Perorangan " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    // }
                }

                // check if lampiran id is in list of lampiran syarat umum
                if (in_array(strtolower($bin->lampiran_id), $listSyaratUmum)) {
                    $payloadDocument = [
                        "NIK" => $fileNik,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Syarat Umum",
                        "DataBase64" => $binS3Base64
                    ];
                    Log::info('Sending lampiran '  .  $namaJenis . ' Syarat Umum to Creatio', [
                        'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                        'time' => now()->toDateTimeString(),
                    ]);
                    // $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    // LOG::info("check inside looping {$response} {$namaJenis} {$debiturFileName}");
                    // if ($response->status() !== 200) {
                    //     throw new Exception("Failed to Send Document penjaminan Syarat Umum " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    // }
                    // $bodyResponse = json_decode($response->body(), true);
                    // if ($bodyResponse['Success'] !== true) {
                    //     throw new Exception("Failed to Send penjaminan Syarat Umum " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    // }
                }

                // check if lampiran id is in list of lampiran syarat khusus
                if (in_array(strtolower($bin->lampiran_id), $listSyaratKhusus)) {
                    $payloadDocument = [
                        "NIK" => $fileNik,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Syarat Khusus",
                        "DataBase64" => $binS3Base64
                    ];
                    Log::info('Sending lampiran ' .  $namaJenis . ' Syarat Khusus to Creatio', [
                        'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                        'time' => now()->toDateTimeString(),
                    ]);
                    // $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    // LOG::info("check inside looping {$response} {$namaJenis} {$debiturFileName}");
                    // if ($response->status() !== 200) {
                    //     throw new Exception("Failed to Send Document penjaminan Syarat Khusus " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    // }
                    // $bodyResponse = json_decode($response->body(), true);
                    // if ($bodyResponse['Success'] !== true) {
                    //     throw new Exception("Failed to Send penjaminan Syarat Khusus " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    // }
                }
            }
            Log::info('END iterate lampiran multiguna', [
                'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                'time' => now()->toDateTimeString(),
            ]);
            // dd("upload done");
            // (END) 2. temporarily remarked (send lampiran to core creatio)
            // temporarily remark send notif to core
            // $notifCreatioPayload = [
            //     "Title" => "Mitra Portal Notification",
            //     "Subject" => "Register Penjaminan Success",
            //     // "Contact" => $request->nama
            //     "Contact" => "Supervisor"
            // ];
            // $notifRes = $svcPenjCreatio->request('post', '/0/rest/Notification/SendNotification', $notifCreatioPayload);
            // $notifResBody = json_decode($notifRes->body(), true);
            // (END) temporarily remark send notif to core

            // $noTelp = $penjaminan->no_telp;
            // if (str_starts_with($noTelp, '0')) {
            //     $noTelp = '62' . substr($noTelp, 1); 
            // }
            // LOG::info("reaching final update for penjaminan {$trx_no} with phone number {$noTelp}");

            PenjaminanTransaction::where('trx_no', $trx_no)->update([
                'status_sync_creatio' => 1,
                'trx_status' => $finalTrxStatus,
                'updated_by_id' => $user_id,
                'updated_by_name' => $user_name,
                'updated_at' => $nowJakarta,
            ]);

            PenjaminanFlow::insert([
                'trx_no' => $trx_no,
                'trx_status' => $finalTrxStatus,
                'created_at' => $nowJakarta,
                'created_by_id' => auth('sanctum')->user()->user_id,
                'created_by_name' => auth('sanctum')->user()->name,
                'status' => true
            ]);

            NotifMitra::create([
                'mitra_user_id' => auth('sanctum')->user()->user_id,
                'title' => "Mitra Portal - Penjaminan Multiguna",
                'message' => "Status penjaminan multiguna dengan nomor " . $trx_no . " menjadi " . "Submitted",
            ]);

            DB::commit();
            Log::info("Penjaminan Multiguna {$trx_no} approved successfully.");
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Error approving penjaminan multi {$trx_no}: {$e->getMessage()}");
            throw $e;
        }
    }

    public function approvePenjaminanKPR(string $trx_no, ?string $user_id = null, ?string $user_name = null, ?string $sources = null)
    {
        ini_set('max_execution_time', 0);
        try {
            DB::beginTransaction();
            Log::info('User ID for approval: ' . $user_id . ' Name: ' . $user_name);
            $penjaminan = PenjaminanTransaction::join('multiguna_trx_kpr as mtk', 'transaction_penjaminan_header.trx_no', '=', 'mtk.trx_no')
                ->where('transaction_penjaminan_header.trx_no', $trx_no)
                ->where('transaction_penjaminan_header.status_sync_creatio', 0)
                ->first();
            if (!$penjaminan) {
                throw new Exception("Penjaminan {$trx_no} not found or already synced.");
            }
            Log::info('regist multiguna', [
                'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                'time' => now()->toDateTimeString(),
            ]);
            // temporarily remark regist penjaminan ot core
            $debiturs = TrxDebiturKprModel::where('id_multiguna_kpr', $penjaminan->id_multiguna_kpr)->get();
            $allCAC = $debiturs->every(fn($d) => ($d->jenis_penjaminan ?? null) === 'CAC');
            $allCBC = $debiturs->every(fn($d) => ($d->jenis_penjaminan ?? null) === 'CBC');
            $finalTrxStatus = null;
            if ((!$allCAC && !$allCBC) || ($allCAC)) {
                $finalTrxStatus = 'WFP';
            } elseif ($allCBC) {
                $finalTrxStatus = 'S';
            }
            $institutionIds = $debiturs->pluck('institution_id')->filter()->unique()->values();
            $institutionsById = Institution::whereIn('institution_id', $institutionIds)
                ->get()
                ->keyBy('institution_id');
            $debitursArray = $debiturs->map(function ($d) use ($institutionsById) {
                $arr = $d->toArray();
                $arr['nik'] = $d->nik;
                $arr['birth_date'] = $institutionsById[$d->institution_id]->birth_date ?? null;
                $arr['gender'] = $institutionsById[$d->institution_id]->gender ?? null;
                return $arr;
            })->values()->all();

            $dataMitra = Mitra::where('mitra_id', $penjaminan->mitra_id)->first();
            $nowJakarta = Carbon::now('Asia/Jakarta');
            // $creatioPayload = [
            //     "PermohonanPenjaminanMultiguna" => [
            //         [
            //             "CaraBayar" => $penjaminan->sp_split == true ? 'Installment' : 'Full Payment',
            //             "PKS" => $penjaminan->pks_number ?? '0018/JJ-1/KBP.00.06/PIK/2025',
            //             "TanggalSuratPermohonan" => $penjaminan->tanggal_surat_permohonan ?? '2025-10-27',
            //             "NomorSuratPermohonan" => $penjaminan->no_surat_permohonan ?? '1000023-Testing003',
            //             "MitraId" => 'MDR',
            //             "TarifPercentage" => (int)  $penjaminan->fee_base_number ?? 2.00,
            //             "NomorPermohonan" => $penjaminan->no_surat_permohonan ?? 'Testing003',
            //             "BankCabang" => $penjaminan->bank_code . ' - ' . $penjaminan->bank_name ?? 'BANK MANDIRI - Mandiri Sudirman',
            //             "FeeBasePercentage" => (int)  $penjaminan->fee_base_percentage ?? 10.00,
            //             "TeksPercentagePenjaminandiSP" => (int) $penjaminan->text_certified ?? 70.00,
            //             "ListDebitur" => collect($debitursArray)->map(function (array $d) use ($nowJakarta) {
            //                 return [
            //                     "Name" => $d['debitur_name'] ?? 'Doricky',
            //                     "Nik" => $d['nik'] ?? '1231231231231231',
            //                     "NamaMakhfulAnhu" => $d['jenis_makful_anhu'] ?? 'Testing',
            //                     "TanggalLahir" => $d['birth_date'] ?? '1995-08-29',
            //                     "NilaiKafalah" => (int)  $d['nilai_kafalah'] ?? 70000000,
            //                     "TanggalRealisasi" => $d['tanggal_realisasi'] ?? '2028-08-29',
            //                     "NilaiAgunan" => (int) $d['nilai_agunan'] ?? 10000000,
            //                     "JenisAgunan" => $d['jenis_agunan'] ?? 'IJK',
            //                     "JenisMakhfulAnhu" => $d['jenis_makful_anhu'] ?? 'Testing',
            //                     "InstansiPekerjaanTerjamin" => $d['institution_name'] ?? 'Testing',
            //                     "JwBulan" => (int) $d['jw_bulan'] ?? 12,
            //                     "JenisKelamin" => (int) $d['gender'] == "L" ? "Laki-Laki" : "Perempuan",
            //                     "MarginBagiHasilUjrahTahun" => (int) $d['margin'] ?? 120000,
            //                     "JumlahDana" => (int) $d['plafond_pembiayaan'] ?? 100000,
            //                     "PlafonPembiayaan" => (int) $d['plafond_pembiayaan'] ?? 10000000,
            //                     "LoanNumber" => $d['loan_number'] ?? '1231203',
            //                     "TanggalJatuhTempo" => $d['tanggal_jatuh_tempo'] ?? '2028-08-29',
            //                     "PenggunaanPembiayaan" => $d['penggunaan_pembiayaan'] ?? 'Testing',
            //                     "TenagaKerja" => $d['tenaga_kerja'] ?? 'Testng',
            //                     "Tanggal" => $nowJakarta->toDateString(),
            //                     "Tenor" => (int) $d['jw_bulan'] ?? 12,
            //                 ];
            //             })->values()->all(),
            //         ]
            //     ]
            // ];
            // $svcPenjCreatio = new CreatioService();
            // $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/RegistrasiMultiguna', $creatioPayload);
            // // dd($response);
            // if ($response->status() !== 200) {
            //     throw new Exception("Failed to register Penjaminan Multiguna to Core Creatio API with status: " . $response->status());
            // }

            // $bodyResponse = json_decode($response->body(), true);
            // if ($bodyResponse['Success'] !== true) {
            //     throw new Exception("Failed to register Penjaminan Multiguna to Core Creatio API with message: " . $bodyResponse['Message']);
            // }
            // if(!is_array($bodyResponse['Data']) || empty($bodyResponse['Data']))
            // {
            //     throw new Exception('Failed to get response from register Penjaminan Multiguna to Core API: empty or unreadable Data from response.');
            // }
            // $responsePayload = $bodyResponse['Data'][0];
            // if(!is_array($responsePayload["Detail"]))
            // {
            //     throw new Exception('Failed to get response from register Penjaminan Multiguna to Core API: unreadable Detail array from response.');
            // }
            // test using dummy response first
            // if($dummyRegistResponse['Success'] !== true) {
            //     throw new Exception("Failed to register Penjaminan Multiguna to Core Creatio API with message: " . $bodyResponse['Message']);
            // }
            // if(!is_array($dummyRegistResponse['Data']) || empty($dummyRegistResponse['Data']))
            // {
            //     throw new Exception('Failed to get response from register Penjaminan Multiguna to Core API: empty or unreadable Data from response.');
            // }
            // $responsePayload = $dummyRegistResponse['Data'][0];
            // if(!is_array($responsePayload["Detail"]))
            // {
            //     throw new Exception('Failed to get response from register Penjaminan Multiguna to Core API: unreadable Detail array from response.');
            // }

            // $key = base64_decode(config('services.secure.key'));
            // $debiturQueryData = MultigunaDebitur::where('multiguna_trx_id', $penjaminan->id_multiguna)
            //     ->select('id_trx_debitur', 'no_sp_detail', 'debitur_name', 'debitur_address', 'loan_number', 'nik')
            //     ->get()->map(function($query) use ($key) {
            //         $nikDecrypt = AesHelper::decrypt($query->nik, $key);
            //         $result = [
            //             'id_trx_debitur' => $query->id_trx_debitur,
            //             'no_sp_detail' => $query->no_sp_detail,
            //             'debitur_name' => $query->debitur_name,
            //             'debitur_address' => $query->debitur_address,
            //             'loan_number' => $query->loan_number,
            //             'nik' => $nikDecrypt
            //         ];
            //         return $result;
            //     });
            // $queryCollectArr = collect($debiturQueryData)->toArray();

            Log::info('END regist multiguna', [
                'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                'time' => now()->toDateTimeString(),
            ]);
            // (END) temporarily remark regist penjaminan ot core

            // 1. temporarily remarked (send lampiran to core creatio)
            $binaryLampiran = PenjaminanLampiranDtl::where('trx_no', $trx_no)
                ->select(
                    'trx_no',
                    'lampiran_id',
                    'file_info',
                    'mime_type'
                )->cursor();
            $lampiranCodeList = array_unique($binaryLampiran->pluck('lampiran_id')->toArray());
            $lampiranJenisMapping = MappingValue::where('key', 'lampiran')
                ->whereIn('value', $lampiranCodeList)
                ->select('value', 'option3')
                ->get();

            $listPerorangan = ['ktp', 'npywp'];
            $listSyaratUmum = ['app', 'npwp', 'ppp', 'siujk'];
            $listSyaratKhusus = ['nib', 'rdpj'];
            // (END) 1. temporarily remarked (send lampiran to core creatio)

            // $mapJenisLampiran = [
            //     [
            //         "type" => "Perorangan",
            //         "list" => $listPerorangan
            //     ],
            //     [
            //         "type" => "Syarat Umum",
            //         "list" => $listSyaratUmum
            //     ],
            //     [
            //         "type" => "Syarat Khusus",
            //         "list" => $listSyaratKhusus
            //     ]
            // ];
            // dd($binaryLampiran);


            Log::info('iterate lampiran multiguna', [
                'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                'time' => now()->toDateTimeString(),
            ]);
            // 2. temporarily remarked (send lampiran to core creatio)
            foreach ($binaryLampiran as $bin) {
                Log::info('Get NIK and base64 lampiran ' .  $bin->lampiran_id, [
                    'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                    'time' => now()->toDateTimeString(),
                ]);
                $binFileNameParse = explode('/', $bin->file_info);
                $debiturFileName = $binFileNameParse[count($binFileNameParse) - 1];
                $fileNameParse = explode('-', $debiturFileName);
                $fileNik = $fileNameParse[0];
                $binS3Content = Storage::disk('s3')->get($bin->file_info);
                $binS3Base64 = base64_encode($binS3Content);
                $jenisByLampiranId = $lampiranJenisMapping
                    ->firstWhere('value', strtolower($bin->lampiran_id));
                $namaJenis = $jenisByLampiranId ? $jenisByLampiranId->option3 : "";
                LOG::info("check jenis dokumen {$jenisByLampiranId}");
                // check if lampiran id is in list of lampiran perorangan
                if (in_array(strtolower($bin->lampiran_id), $listPerorangan)) {
                    $payloadDocument = [
                        "NIK" => $fileNik,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Perorangan",
                        "DataBase64" => $binS3Base64
                    ];
                    Log::info('Sending lampiran ' .  $namaJenis . ' Perorangan to Creatio', [
                        'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                        'time' => now()->toDateTimeString(),
                    ]);
                    // $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    // LOG::info("check inside looping {$response} {$namaJenis} {$debiturFileName}");
                    // if ($response->status() !== 200) {
                    //     throw new Exception("Failed to Send Document penjaminan Perorangan " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    // }
                    // $bodyResponse = json_decode($response->body(), true);
                    // if ($bodyResponse['Success'] !== true) {
                    //     throw new Exception("Failed to Send penjaminan Perorangan " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    // }
                }

                // check if lampiran id is in list of lampiran syarat umum
                if (in_array(strtolower($bin->lampiran_id), $listSyaratUmum)) {
                    $payloadDocument = [
                        "NIK" => $fileNik,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Syarat Umum",
                        "DataBase64" => $binS3Base64
                    ];
                    Log::info('Sending lampiran '  .  $namaJenis . ' Syarat Umum to Creatio', [
                        'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                        'time' => now()->toDateTimeString(),
                    ]);
                    // $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    // LOG::info("check inside looping {$response} {$namaJenis} {$debiturFileName}");
                    // if ($response->status() !== 200) {
                    //     throw new Exception("Failed to Send Document penjaminan Syarat Umum " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    // }
                    // $bodyResponse = json_decode($response->body(), true);
                    // if ($bodyResponse['Success'] !== true) {
                    //     throw new Exception("Failed to Send penjaminan Syarat Umum " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    // }
                }

                // check if lampiran id is in list of lampiran syarat khusus
                if (in_array(strtolower($bin->lampiran_id), $listSyaratKhusus)) {
                    $payloadDocument = [
                        "NIK" => $fileNik,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Syarat Khusus",
                        "DataBase64" => $binS3Base64
                    ];
                    Log::info('Sending lampiran ' .  $namaJenis . ' Syarat Khusus to Creatio', [
                        'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                        'time' => now()->toDateTimeString(),
                    ]);
                    // $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    // LOG::info("check inside looping {$response} {$namaJenis} {$debiturFileName}");
                    // if ($response->status() !== 200) {
                    //     throw new Exception("Failed to Send Document penjaminan Syarat Khusus " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    // }
                    // $bodyResponse = json_decode($response->body(), true);
                    // if ($bodyResponse['Success'] !== true) {
                    //     throw new Exception("Failed to Send penjaminan Syarat Khusus " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    // }
                }
            }
            Log::info('END iterate lampiran multiguna', [
                'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                'time' => now()->toDateTimeString(),
            ]);
            // dd("upload done");
            // (END) 2. temporarily remarked (send lampiran to core creatio)
            // temporarily remark send notif to core
            // $notifCreatioPayload = [
            //     "Title" => "Mitra Portal Notification",
            //     "Subject" => "Register Penjaminan Success",
            //     // "Contact" => $request->nama
            //     "Contact" => "Supervisor"
            // ];
            // $notifRes = $svcPenjCreatio->request('post', '/0/rest/Notification/SendNotification', $notifCreatioPayload);
            // $notifResBody = json_decode($notifRes->body(), true);
            // (END) temporarily remark send notif to core

            // $noTelp = $penjaminan->no_telp;
            // if (str_starts_with($noTelp, '0')) {
            //     $noTelp = '62' . substr($noTelp, 1); 
            // }
            // LOG::info("reaching final update for penjaminan {$trx_no} with phone number {$noTelp}");

            PenjaminanTransaction::where('trx_no', $trx_no)->update([
                'status_sync_creatio' => 1,
                'trx_status' => $finalTrxStatus,
                'updated_by_id' => $user_id,
                'updated_by_name' => $user_name,
                'updated_at' => $nowJakarta,
            ]);

            PenjaminanFlow::insert([
                'trx_no' => $trx_no,
                'trx_status' => $finalTrxStatus,
                'created_at' => $nowJakarta,
                'created_by_id' => auth('sanctum')->user()->user_id,
                'created_by_name' => auth('sanctum')->user()->name,
                'status' => true
            ]);

            NotifMitra::create([
                'mitra_user_id' => auth('sanctum')->user()->user_id,
                'title' => "Mitra Portal - Penjaminan Multiguna",
                'message' => "Status penjaminan multiguna dengan nomor " . $trx_no . " menjadi " . "Submitted",
            ]);

            DB::commit();
            Log::info("Penjaminan Multiguna {$trx_no} approved successfully.");
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Error approving penjaminan multi {$trx_no}: {$e->getMessage()}");
            throw $e;
        }
    }

    public function approvePenjaminanAJP(string $trx_no, ?string $user_id = null, ?string $user_name = null, ?string $sources = null)
    {
        ini_set('max_execution_time', 0);

        try {
            DB::beginTransaction();
            Log::info('User ID for approval: ' . $user_id . ' Name: ' . $user_name);

            $penjaminan = PenjaminanTransaction::join('multiguna_trx_ajp as mta', 'transaction_penjaminan_header.trx_no', '=', 'mta.trx_no')
                ->where('transaction_penjaminan_header.trx_no', $trx_no)
                ->where('transaction_penjaminan_header.status_sync_creatio', 0)
                ->first();

            if (!$penjaminan) {
                throw new Exception("Penjaminan {$trx_no} not found or already synced.");
            }

            $debiturs = TrxDebiturAjpModel::where('id_multiguna_ajp', $penjaminan->id_multiguna_ajp)->get();
            $allCAC = $debiturs->every(fn($d) => ($d->jenis_penjaminan ?? null) === 'CAC');
            $allCBC = $debiturs->every(fn($d) => ($d->jenis_penjaminan ?? null) === 'CBC');
            $finalTrxStatus = null;

            if ((!$allCAC && !$allCBC) || $allCAC) {
                $finalTrxStatus = 'WFP';
            } elseif ($allCBC) {
                $finalTrxStatus = 'S';
            }

            $nowJakarta = Carbon::now('Asia/Jakarta');

            $binaryLampiran = PenjaminanLampiranDtl::where('trx_no', $trx_no)
                ->select(
                    'trx_no',
                    'lampiran_id',
                    'file_info',
                    'mime_type'
                )
                ->cursor();

            $lampiranCodeList = array_unique($binaryLampiran->pluck('lampiran_id')->toArray());
            $lampiranJenisMapping = MappingValue::where('key', 'lampiran')
                ->whereIn('value', $lampiranCodeList)
                ->select('value', 'option3')
                ->get();

            $listPerorangan = ['ktp', 'npywp'];
            $listSyaratUmum = ['app', 'npwp', 'ppp', 'siujk'];
            $listSyaratKhusus = ['nib', 'rdpj'];

            foreach ($binaryLampiran as $bin) {
                $binFileNameParse = explode('/', $bin->file_info);
                $debiturFileName = $binFileNameParse[count($binFileNameParse) - 1];
                $fileNameParse = explode('-', $debiturFileName);
                $fileNik = $fileNameParse[0];
                $binS3Content = Storage::disk('s3')->get($bin->file_info);
                $binS3Base64 = base64_encode($binS3Content);
                $jenisByLampiranId = $lampiranJenisMapping->firstWhere('value', strtolower($bin->lampiran_id));
                $namaJenis = $jenisByLampiranId ? $jenisByLampiranId->option3 : "";

                if (in_array(strtolower($bin->lampiran_id), $listPerorangan)) {
                    $payloadDocument = [
                        "NIK" => $fileNik,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Perorangan",
                        "DataBase64" => $binS3Base64
                    ];
                }

                if (in_array(strtolower($bin->lampiran_id), $listSyaratUmum)) {
                    $payloadDocument = [
                        "NIK" => $fileNik,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Syarat Umum",
                        "DataBase64" => $binS3Base64
                    ];
                }

                if (in_array(strtolower($bin->lampiran_id), $listSyaratKhusus)) {
                    $payloadDocument = [
                        "NIK" => $fileNik,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Syarat Khusus",
                        "DataBase64" => $binS3Base64
                    ];
                }
            }

            PenjaminanTransaction::where('trx_no', $trx_no)->update([
                'status_sync_creatio' => 1,
                'trx_status' => $finalTrxStatus,
                'updated_by_id' => $user_id,
                'updated_by_name' => $user_name,
                'updated_at' => $nowJakarta,
            ]);

            PenjaminanFlow::insert([
                'trx_no' => $trx_no,
                'trx_status' => $finalTrxStatus,
                'created_at' => $nowJakarta,
                'created_by_id' => auth('sanctum')->user()->user_id,
                'created_by_name' => auth('sanctum')->user()->name,
                'status' => true
            ]);

            NotifMitra::create([
                'mitra_user_id' => auth('sanctum')->user()->user_id,
                'title' => "Mitra Portal - Penjaminan AJP",
                'message' => "Status penjaminan AJP dengan nomor " . $trx_no . " menjadi Submitted",
            ]);

            DB::commit();
            Log::info("Penjaminan AJP {$trx_no} approved successfully.");
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Error approving penjaminan AJP {$trx_no}: {$e->getMessage()}");
            throw $e;
        }
    }

    public function approvePenjaminanKreditUsaha(string $trx_no, ?string $user_id = null, ?string $user_name = null, ?string $sources = null)
    {
        ini_set('max_execution_time', 0);
        try {
            DB::beginTransaction();
            Log::info('User ID for approval: ' . $user_id . ' Name: ' . $user_name);
            $penjaminan = PenjaminanTransaction::join('kredit_usaha_transaction as kut', 'transaction_penjaminan_header.trx_no', '=', 'kut.trx_no')
                ->where('transaction_penjaminan_header.trx_no', $trx_no)
                ->where('transaction_penjaminan_header.status_sync_creatio', 0)
                ->first();
            if (!$penjaminan) {
                throw new Exception("Penjaminan {$trx_no} not found or already synced.");
            }
            Log::info('regist Kredit Usaha', [
                'endpoint' => 'PenjaminanService@approvePenjaminanKreditUsaha',
                'time' => now()->toDateTimeString(),
            ]);

            $debiturs = TrxDebiturDefaultBase::where('kredit_usaha_trx_id', $penjaminan->id_kredit_usaha_transaction)->get();

            $allCAC = $debiturs->every(fn($d) => ($d->jenis_penjaminan ?? null) === 'CAC');
            $allCBC = $debiturs->every(fn($d) => ($d->jenis_penjaminan ?? null) === 'CBC');
            $finalTrxStatus = null;
            if ((!$allCAC && !$allCBC) || ($allCAC)) {
                $finalTrxStatus = 'WFP';
            } elseif ($allCBC) {
                $finalTrxStatus = 'S';
            }
            $institutionIds = $debiturs->pluck('institution_id')->filter()->unique()->values();
            $institutionsById = Institution::whereIn('institution_id', $institutionIds)
                ->get()
                ->keyBy('institution_id');
            $debitursArray = $debiturs->map(function ($d) use ($institutionsById) {
                $arr = $d->toArray();
                $arr['nik'] = $d->nik;
                $arr['birth_date'] = $institutionsById[$d->institution_id]->birth_date ?? null;
                $arr['gender'] = $institutionsById[$d->institution_id]->gender ?? null;
                return $arr;
            })->values()->all();

            $dataMitra = Mitra::where('mitra_id', $penjaminan->mitra_id)->first();
            $nowJakarta = Carbon::now('Asia/Jakarta');
            // $creatioPayload = [
            //     "PermohonanPenjaminanMultiguna" => [
            //         [
            //             "CaraBayar" => $penjaminan->sp_split == true ? 'Installment' : 'Full Payment',
            //             "PKS" => $penjaminan->pks_number ?? '0018/JJ-1/KBP.00.06/PIK/2025',
            //             "TanggalSuratPermohonan" => $penjaminan->tanggal_surat_permohonan ?? '2025-10-27',
            //             "NomorSuratPermohonan" => $penjaminan->no_surat_permohonan ?? '1000023-Testing003',
            //             "MitraId" => 'MDR',
            //             "TarifPercentage" => (int)  $penjaminan->fee_base_number ?? 2.00,
            //             "NomorPermohonan" => $penjaminan->no_surat_permohonan ?? 'Testing003',
            //             "BankCabang" => $penjaminan->bank_code . ' - ' . $penjaminan->bank_name ?? 'BANK MANDIRI - Mandiri Sudirman',
            //             "FeeBasePercentage" => (int)  $penjaminan->fee_base_percentage ?? 10.00,
            //             "TeksPercentagePenjaminandiSP" => (int) $penjaminan->text_certified ?? 70.00,
            //             "ListDebitur" => collect($debitursArray)->map(function (array $d) use ($nowJakarta) {
            //                 return [
            //                     "Name" => $d['debitur_name'] ?? 'Doricky',
            //                     "Nik" => $d['nik'] ?? '1231231231231231',
            //                     "NamaMakhfulAnhu" => $d['jenis_makful_anhu'] ?? 'Testing',
            //                     "TanggalLahir" => $d['birth_date'] ?? '1995-08-29',
            //                     "NilaiKafalah" => (int)  $d['nilai_kafalah'] ?? 70000000,
            //                     "TanggalRealisasi" => $d['tanggal_realisasi'] ?? '2028-08-29',
            //                     "NilaiAgunan" => (int) $d['nilai_agunan'] ?? 10000000,
            //                     "JenisAgunan" => $d['jenis_agunan'] ?? 'IJK',
            //                     "JenisMakhfulAnhu" => $d['jenis_makful_anhu'] ?? 'Testing',
            //                     "InstansiPekerjaanTerjamin" => $d['institution_name'] ?? 'Testing',
            //                     "JwBulan" => (int) $d['jw_bulan'] ?? 12,
            //                     "JenisKelamin" => (int) $d['gender'] == "L" ? "Laki-Laki" : "Perempuan",
            //                     "MarginBagiHasilUjrahTahun" => (int) $d['margin'] ?? 120000,
            //                     "JumlahDana" => (int) $d['plafond_pembiayaan'] ?? 100000,
            //                     "PlafonPembiayaan" => (int) $d['plafond_pembiayaan'] ?? 10000000,
            //                     "LoanNumber" => $d['loan_number'] ?? '1231203',
            //                     "TanggalJatuhTempo" => $d['tanggal_jatuh_tempo'] ?? '2028-08-29',
            //                     "PenggunaanPembiayaan" => $d['penggunaan_pembiayaan'] ?? 'Testing',
            //                     "TenagaKerja" => $d['tenaga_kerja'] ?? 'Testng',
            //                     "Tanggal" => $nowJakarta->toDateString(),
            //                     "Tenor" => (int) $d['jw_bulan'] ?? 12,
            //                 ];
            //             })->values()->all(),
            //         ]
            //     ]
            // ];
            // $svcPenjCreatio = new CreatioService();
            // $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/RegistrasiMultiguna', $creatioPayload);
            // // dd($response);
            // if ($response->status() !== 200) {
            //     throw new Exception("Failed to register Penjaminan Multiguna to Core Creatio API with status: " . $response->status());
            // }

            // $bodyResponse = json_decode($response->body(), true);
            // if ($bodyResponse['Success'] !== true) {
            //     throw new Exception("Failed to register Penjaminan Multiguna to Core Creatio API with message: " . $bodyResponse['Message']);
            // }
            // if(!is_array($bodyResponse['Data']) || empty($bodyResponse['Data']))
            // {
            //     throw new Exception('Failed to get response from register Penjaminan Multiguna to Core API: empty or unreadable Data from response.');
            // }
            // $responsePayload = $bodyResponse['Data'][0];
            // if(!is_array($responsePayload["Detail"]))
            // {
            //     throw new Exception('Failed to get response from register Penjaminan Multiguna to Core API: unreadable Detail array from response.');
            // }
            // test using dummy response first
            // if($dummyRegistResponse['Success'] !== true) {
            //     throw new Exception("Failed to register Penjaminan Multiguna to Core Creatio API with message: " . $bodyResponse['Message']);
            // }
            // if(!is_array($dummyRegistResponse['Data']) || empty($dummyRegistResponse['Data']))
            // {
            //     throw new Exception('Failed to get response from register Penjaminan Multiguna to Core API: empty or unreadable Data from response.');
            // }
            // $responsePayload = $dummyRegistResponse['Data'][0];
            // if(!is_array($responsePayload["Detail"]))
            // {
            //     throw new Exception('Failed to get response from register Penjaminan Multiguna to Core API: unreadable Detail array from response.');
            // }

            // $key = base64_decode(config('services.secure.key'));
            // $debiturQueryData = MultigunaDebitur::where('multiguna_trx_id', $penjaminan->id_multiguna)
            //     ->select('id_trx_debitur', 'no_sp_detail', 'debitur_name', 'debitur_address', 'loan_number', 'nik')
            //     ->get()->map(function($query) use ($key) {
            //         $nikDecrypt = AesHelper::decrypt($query->nik, $key);
            //         $result = [
            //             'id_trx_debitur' => $query->id_trx_debitur,
            //             'no_sp_detail' => $query->no_sp_detail,
            //             'debitur_name' => $query->debitur_name,
            //             'debitur_address' => $query->debitur_address,
            //             'loan_number' => $query->loan_number,
            //             'nik' => $nikDecrypt
            //         ];
            //         return $result;
            //     });
            // $queryCollectArr = collect($debiturQueryData)->toArray();

            Log::info('END regist Kredit Usaha', [
                'endpoint' => 'PenjaminanService@approvePenjaminanKreditUsaha',
                'time' => now()->toDateTimeString(),
            ]);
            // (END) temporarily remark regist penjaminan ot core

            // 1. temporarily remarked (send lampiran to core creatio)
            $binaryLampiran = PenjaminanLampiranDtl::where('trx_no', $trx_no)
                ->select(
                    'trx_no',
                    'lampiran_id',
                    'file_info',
                    'mime_type'
                )->cursor();
            $lampiranCodeList = array_unique($binaryLampiran->pluck('lampiran_id')->toArray());
            $lampiranJenisMapping = MappingValue::where('key', 'lampiran')
                ->whereIn('value', $lampiranCodeList)
                ->select('value', 'option3')
                ->get();

            $listPerorangan = ['ktp', 'npywp'];
            $listSyaratUmum = ['app', 'npwp', 'ppp', 'siujk'];
            $listSyaratKhusus = ['nib', 'rdpj'];
            // (END) 1. temporarily remarked (send lampiran to core creatio)

            // $mapJenisLampiran = [
            //     [
            //         "type" => "Perorangan",
            //         "list" => $listPerorangan
            //     ],
            //     [
            //         "type" => "Syarat Umum",
            //         "list" => $listSyaratUmum
            //     ],
            //     [
            //         "type" => "Syarat Khusus",
            //         "list" => $listSyaratKhusus
            //     ]
            // ];
            // dd($binaryLampiran);


            Log::info('iterate lampiran Kredit Usaha', [
                'endpoint' => 'PenjaminanService@approvePenjaminanKreditUsaha',
                'time' => now()->toDateTimeString(),
            ]);
            // 2. temporarily remarked (send lampiran to core creatio)
            foreach ($binaryLampiran as $bin) {
                Log::info('Get NIK and base64 lampiran ' .  $bin->lampiran_id, [
                    'endpoint' => 'PenjaminanService@approvePenjaminanKreditUsaha',
                    'time' => now()->toDateTimeString(),
                ]);
                $binFileNameParse = explode('/', $bin->file_info);
                $debiturFileName = $binFileNameParse[count($binFileNameParse) - 1];
                $fileNameParse = explode('-', $debiturFileName);
                $fileNik = $fileNameParse[0];
                $binS3Content = Storage::disk('s3')->get($bin->file_info);
                $binS3Base64 = base64_encode($binS3Content);
                $jenisByLampiranId = $lampiranJenisMapping
                    ->firstWhere('value', strtolower($bin->lampiran_id));
                $namaJenis = $jenisByLampiranId ? $jenisByLampiranId->option3 : "";
                LOG::info("check jenis dokumen {$jenisByLampiranId}");
                // check if lampiran id is in list of lampiran perorangan
                if (in_array(strtolower($bin->lampiran_id), $listPerorangan)) {
                    $payloadDocument = [
                        "NIK" => $fileNik,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Perorangan",
                        "DataBase64" => $binS3Base64
                    ];
                    Log::info('Sending lampiran ' .  $namaJenis . ' Perorangan to Creatio', [
                        'endpoint' => 'PenjaminanService@approvePenjaminanKreditUsaha',
                        'time' => now()->toDateTimeString(),
                    ]);
                    // $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    // LOG::info("check inside looping {$response} {$namaJenis} {$debiturFileName}");
                    // if ($response->status() !== 200) {
                    //     throw new Exception("Failed to Send Document penjaminan Perorangan " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    // }
                    // $bodyResponse = json_decode($response->body(), true);
                    // if ($bodyResponse['Success'] !== true) {
                    //     throw new Exception("Failed to Send penjaminan Perorangan " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    // }
                }

                // check if lampiran id is in list of lampiran syarat umum
                if (in_array(strtolower($bin->lampiran_id), $listSyaratUmum)) {
                    $payloadDocument = [
                        "NIK" => $fileNik,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Syarat Umum",
                        "DataBase64" => $binS3Base64
                    ];
                    Log::info('Sending lampiran '  .  $namaJenis . ' Syarat Umum to Creatio', [
                        'endpoint' => 'PenjaminanService@approvePenjaminanKreditUsaha',
                        'time' => now()->toDateTimeString(),
                    ]);
                    // $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    // LOG::info("check inside looping {$response} {$namaJenis} {$debiturFileName}");
                    // if ($response->status() !== 200) {
                    //     throw new Exception("Failed to Send Document penjaminan Syarat Umum " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    // }
                    // $bodyResponse = json_decode($response->body(), true);
                    // if ($bodyResponse['Success'] !== true) {
                    //     throw new Exception("Failed to Send penjaminan Syarat Umum " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    // }
                }

                // check if lampiran id is in list of lampiran syarat khusus
                if (in_array(strtolower($bin->lampiran_id), $listSyaratKhusus)) {
                    $payloadDocument = [
                        "NIK" => $fileNik,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Syarat Khusus",
                        "DataBase64" => $binS3Base64
                    ];
                    Log::info('Sending lampiran ' .  $namaJenis . ' Syarat Khusus to Creatio', [
                        'endpoint' => 'PenjaminanService@approvePenjaminanKreditUsaha',
                        'time' => now()->toDateTimeString(),
                    ]);
                    // $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    // LOG::info("check inside looping {$response} {$namaJenis} {$debiturFileName}");
                    // if ($response->status() !== 200) {
                    //     throw new Exception("Failed to Send Document penjaminan Syarat Khusus " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    // }
                    // $bodyResponse = json_decode($response->body(), true);
                    // if ($bodyResponse['Success'] !== true) {
                    //     throw new Exception("Failed to Send penjaminan Syarat Khusus " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    // }
                }
            }
            Log::info('END iterate lampiran multiguna', [
                'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                'time' => now()->toDateTimeString(),
            ]);
            // dd("upload done");
            // (END) 2. temporarily remarked (send lampiran to core creatio)
            // temporarily remark send notif to core
            // $notifCreatioPayload = [
            //     "Title" => "Mitra Portal Notification",
            //     "Subject" => "Register Penjaminan Success",
            //     // "Contact" => $request->nama
            //     "Contact" => "Supervisor"
            // ];
            // $notifRes = $svcPenjCreatio->request('post', '/0/rest/Notification/SendNotification', $notifCreatioPayload);
            // $notifResBody = json_decode($notifRes->body(), true);
            // (END) temporarily remark send notif to core

            // $noTelp = $penjaminan->no_telp;
            // if (str_starts_with($noTelp, '0')) {
            //     $noTelp = '62' . substr($noTelp, 1); 
            // }
            // LOG::info("reaching final update for penjaminan {$trx_no} with phone number {$noTelp}");

            PenjaminanTransaction::where('trx_no', $trx_no)->update([
                'status_sync_creatio' => 1,
                'trx_status' => $finalTrxStatus,
                'updated_by_id' => $user_id,
                'updated_by_name' => $user_name,
                'updated_at' => $nowJakarta,
            ]);

            PenjaminanFlow::insert([
                'trx_no' => $trx_no,
                'trx_status' => $finalTrxStatus,
                'created_at' => $nowJakarta,
                'created_by_id' => auth('sanctum')->user()->user_id,
                'created_by_name' => auth('sanctum')->user()->name,
                'status' => true
            ]);

            NotifMitra::create([
                'mitra_user_id' => auth('sanctum')->user()->user_id,
                'title' => "Mitra Portal - Penjaminan Kredit Usaha",
                'message' => "Status penjaminan kredit usaha dengan nomor " . $trx_no . " menjadi " . "Submitted",
            ]);

            DB::commit();
            Log::info("Penjaminan Kredit Usaha {$trx_no} approved successfully.");
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Error approving penjaminan kredit usaha {$trx_no}: {$e->getMessage()}");
            throw $e;
        }
    }

    public function approvePenjaminanKUR(string $trx_no, ?string $user_id = null, ?string $user_name = null, ?string $sources = null)
    {
        ini_set('max_execution_time', 0);
        try {
            DB::beginTransaction();
            Log::info('User ID for approval: ' . $user_id . ' Name: ' . $user_name);
            $penjaminan = PenjaminanTransaction::join('kur_transaction as kur', 'transaction_penjaminan_header.trx_no', '=', 'kur.trx_no')
                ->where('transaction_penjaminan_header.trx_no', $trx_no)
                ->where('transaction_penjaminan_header.status_sync_creatio', 0)
                ->first();
            if (!$penjaminan) {
                throw new Exception("Penjaminan {$trx_no} not found or already synced.");
            }
            Log::info('regist Kredit Usaha Rakyat (KUR)', [
                'endpoint' => 'PenjaminanService@approvePenjaminanKUR',
                'time' => now()->toDateTimeString(),
            ]);

            $debiturs = TrxDebiturDefaultBase::where('kur_trx_id', $penjaminan->id_kur)->get();

            $allCAC = $debiturs->every(fn($d) => ($d->jenis_penjaminan ?? null) === 'CAC');
            $allCBC = $debiturs->every(fn($d) => ($d->jenis_penjaminan ?? null) === 'CBC');
            $finalTrxStatus = null;
            if ((!$allCAC && !$allCBC) || ($allCAC)) {
                $finalTrxStatus = 'WFP';
            } elseif ($allCBC) {
                $finalTrxStatus = 'S';
            }
            $institutionIds = $debiturs->pluck('institution_id')->filter()->unique()->values();
            $institutionsById = Institution::whereIn('institution_id', $institutionIds)
                ->get()
                ->keyBy('institution_id');
            $debitursArray = $debiturs->map(function ($d) use ($institutionsById) {
                $arr = $d->toArray();
                $arr['nik'] = $d->nik;
                $arr['birth_date'] = $institutionsById[$d->institution_id]->birth_date ?? null;
                $arr['gender'] = $institutionsById[$d->institution_id]->gender ?? null;
                return $arr;
            })->values()->all();

            // $dataMitra = Mitra::where('mitra_id', $penjaminan->mitra_id)->first();
            $nowJakarta = Carbon::now('Asia/Jakarta');
            // $creatioPayload = [
            //     "PermohonanPenjaminanMultiguna" => [
            //         [
            //             "CaraBayar" => $penjaminan->sp_split == true ? 'Installment' : 'Full Payment',
            //             "PKS" => $penjaminan->pks_number ?? '0018/JJ-1/KBP.00.06/PIK/2025',
            //             "TanggalSuratPermohonan" => $penjaminan->tanggal_surat_permohonan ?? '2025-10-27',
            //             "NomorSuratPermohonan" => $penjaminan->no_surat_permohonan ?? '1000023-Testing003',
            //             "MitraId" => 'MDR',
            //             "TarifPercentage" => (int)  $penjaminan->fee_base_number ?? 2.00,
            //             "NomorPermohonan" => $penjaminan->no_surat_permohonan ?? 'Testing003',
            //             "BankCabang" => $penjaminan->bank_code . ' - ' . $penjaminan->bank_name ?? 'BANK MANDIRI - Mandiri Sudirman',
            //             "FeeBasePercentage" => (int)  $penjaminan->fee_base_percentage ?? 10.00,
            //             "TeksPercentagePenjaminandiSP" => (int) $penjaminan->text_certified ?? 70.00,
            //             "ListDebitur" => collect($debitursArray)->map(function (array $d) use ($nowJakarta) {
            //                 return [
            //                     "Name" => $d['debitur_name'] ?? 'Doricky',
            //                     "Nik" => $d['nik'] ?? '1231231231231231',
            //                     "NamaMakhfulAnhu" => $d['jenis_makful_anhu'] ?? 'Testing',
            //                     "TanggalLahir" => $d['birth_date'] ?? '1995-08-29',
            //                     "NilaiKafalah" => (int)  $d['nilai_kafalah'] ?? 70000000,
            //                     "TanggalRealisasi" => $d['tanggal_realisasi'] ?? '2028-08-29',
            //                     "NilaiAgunan" => (int) $d['nilai_agunan'] ?? 10000000,
            //                     "JenisAgunan" => $d['jenis_agunan'] ?? 'IJK',
            //                     "JenisMakhfulAnhu" => $d['jenis_makful_anhu'] ?? 'Testing',
            //                     "InstansiPekerjaanTerjamin" => $d['institution_name'] ?? 'Testing',
            //                     "JwBulan" => (int) $d['jw_bulan'] ?? 12,
            //                     "JenisKelamin" => (int) $d['gender'] == "L" ? "Laki-Laki" : "Perempuan",
            //                     "MarginBagiHasilUjrahTahun" => (int) $d['margin'] ?? 120000,
            //                     "JumlahDana" => (int) $d['plafond_pembiayaan'] ?? 100000,
            //                     "PlafonPembiayaan" => (int) $d['plafond_pembiayaan'] ?? 10000000,
            //                     "LoanNumber" => $d['loan_number'] ?? '1231203',
            //                     "TanggalJatuhTempo" => $d['tanggal_jatuh_tempo'] ?? '2028-08-29',
            //                     "PenggunaanPembiayaan" => $d['penggunaan_pembiayaan'] ?? 'Testing',
            //                     "TenagaKerja" => $d['tenaga_kerja'] ?? 'Testng',
            //                     "Tanggal" => $nowJakarta->toDateString(),
            //                     "Tenor" => (int) $d['jw_bulan'] ?? 12,
            //                 ];
            //             })->values()->all(),
            //         ]
            //     ]
            // ];
            // $svcPenjCreatio = new CreatioService();
            // $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/RegistrasiMultiguna', $creatioPayload);
            // // dd($response);
            // if ($response->status() !== 200) {
            //     throw new Exception("Failed to register Penjaminan Multiguna to Core Creatio API with status: " . $response->status());
            // }

            // $bodyResponse = json_decode($response->body(), true);
            // if ($bodyResponse['Success'] !== true) {
            //     throw new Exception("Failed to register Penjaminan Multiguna to Core Creatio API with message: " . $bodyResponse['Message']);
            // }
            // if(!is_array($bodyResponse['Data']) || empty($bodyResponse['Data']))
            // {
            //     throw new Exception('Failed to get response from register Penjaminan Multiguna to Core API: empty or unreadable Data from response.');
            // }
            // $responsePayload = $bodyResponse['Data'][0];
            // if(!is_array($responsePayload["Detail"]))
            // {
            //     throw new Exception('Failed to get response from register Penjaminan Multiguna to Core API: unreadable Detail array from response.');
            // }
            // test using dummy response first
            // if($dummyRegistResponse['Success'] !== true) {
            //     throw new Exception("Failed to register Penjaminan Multiguna to Core Creatio API with message: " . $bodyResponse['Message']);
            // }
            // if(!is_array($dummyRegistResponse['Data']) || empty($dummyRegistResponse['Data']))
            // {
            //     throw new Exception('Failed to get response from register Penjaminan Multiguna to Core API: empty or unreadable Data from response.');
            // }
            // $responsePayload = $dummyRegistResponse['Data'][0];
            // if(!is_array($responsePayload["Detail"]))
            // {
            //     throw new Exception('Failed to get response from register Penjaminan Multiguna to Core API: unreadable Detail array from response.');
            // }

            // $key = base64_decode(config('services.secure.key'));
            // $debiturQueryData = MultigunaDebitur::where('multiguna_trx_id', $penjaminan->id_multiguna)
            //     ->select('id_trx_debitur', 'no_sp_detail', 'debitur_name', 'debitur_address', 'loan_number', 'nik')
            //     ->get()->map(function($query) use ($key) {
            //         $nikDecrypt = AesHelper::decrypt($query->nik, $key);
            //         $result = [
            //             'id_trx_debitur' => $query->id_trx_debitur,
            //             'no_sp_detail' => $query->no_sp_detail,
            //             'debitur_name' => $query->debitur_name,
            //             'debitur_address' => $query->debitur_address,
            //             'loan_number' => $query->loan_number,
            //             'nik' => $nikDecrypt
            //         ];
            //         return $result;
            //     });
            // $queryCollectArr = collect($debiturQueryData)->toArray();

            Log::info('END regist Kredit Usaha Rakyat (KUR)', [
                'endpoint' => 'PenjaminanService@approvePenjaminanKUR',
                'time' => now()->toDateTimeString(),
            ]);
            // (END) temporarily remark regist penjaminan ot core

            // 1. temporarily remarked (send lampiran to core creatio)
            $binaryLampiran = PenjaminanLampiranDtl::where('trx_no', $trx_no)
                ->select(
                    'trx_no',
                    'lampiran_id',
                    'file_info',
                    'mime_type'
                )->cursor();
            $lampiranCodeList = array_unique($binaryLampiran->pluck('lampiran_id')->toArray());
            $lampiranJenisMapping = MappingValue::where('key', 'lampiran')
                ->whereIn('value', $lampiranCodeList)
                ->select('value', 'option3')
                ->get();

            $listPerorangan = ['ktp', 'npywp'];
            $listSyaratUmum = ['app', 'npwp', 'ppp', 'siujk'];
            $listSyaratKhusus = ['nib', 'rdpj'];
            // (END) 1. temporarily remarked (send lampiran to core creatio)

            // $mapJenisLampiran = [
            //     [
            //         "type" => "Perorangan",
            //         "list" => $listPerorangan
            //     ],
            //     [
            //         "type" => "Syarat Umum",
            //         "list" => $listSyaratUmum
            //     ],
            //     [
            //         "type" => "Syarat Khusus",
            //         "list" => $listSyaratKhusus
            //     ]
            // ];
            // dd($binaryLampiran);


            Log::info('iterate lampiran Kredit Usaha Rakyat (KUR)', [
                'endpoint' => 'PenjaminanService@approvePenjaminanKUR',
                'time' => now()->toDateTimeString(),
            ]);
            // 2. temporarily remarked (send lampiran to core creatio)
            foreach ($binaryLampiran as $bin) {
                Log::info('Get NIK and base64 lampiran ' .  $bin->lampiran_id, [
                    'endpoint' => 'PenjaminanService@approvePenjaminanKUR',
                    'time' => now()->toDateTimeString(),
                ]);
                $binFileNameParse = explode('/', $bin->file_info);
                $debiturFileName = $binFileNameParse[count($binFileNameParse) - 1];
                $fileNameParse = explode('-', $debiturFileName);
                $fileNik = $fileNameParse[0];
                $binS3Content = Storage::disk('s3')->get($bin->file_info);
                $binS3Base64 = base64_encode($binS3Content);
                $jenisByLampiranId = $lampiranJenisMapping
                    ->firstWhere('value', strtolower($bin->lampiran_id));
                $namaJenis = $jenisByLampiranId ? $jenisByLampiranId->option3 : "";
                LOG::info("check jenis dokumen {$jenisByLampiranId}");
                // check if lampiran id is in list of lampiran perorangan
                if (in_array(strtolower($bin->lampiran_id), $listPerorangan)) {
                    $payloadDocument = [
                        "NIK" => $fileNik,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Perorangan",
                        "DataBase64" => $binS3Base64
                    ];
                    Log::info('Sending lampiran ' .  $namaJenis . ' Perorangan to Creatio', [
                        'endpoint' => 'PenjaminanService@approvePenjaminanKUR',
                        'time' => now()->toDateTimeString(),
                    ]);
                    // $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    // LOG::info("check inside looping {$response} {$namaJenis} {$debiturFileName}");
                    // if ($response->status() !== 200) {
                    //     throw new Exception("Failed to Send Document penjaminan Perorangan " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    // }
                    // $bodyResponse = json_decode($response->body(), true);
                    // if ($bodyResponse['Success'] !== true) {
                    //     throw new Exception("Failed to Send penjaminan Perorangan " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    // }
                }

                // check if lampiran id is in list of lampiran syarat umum
                if (in_array(strtolower($bin->lampiran_id), $listSyaratUmum)) {
                    $payloadDocument = [
                        "NIK" => $fileNik,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Syarat Umum",
                        "DataBase64" => $binS3Base64
                    ];
                    Log::info('Sending lampiran '  .  $namaJenis . ' Syarat Umum to Creatio', [
                        'endpoint' => 'PenjaminanService@approvePenjaminanKUR',
                        'time' => now()->toDateTimeString(),
                    ]);
                    // $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    // LOG::info("check inside looping {$response} {$namaJenis} {$debiturFileName}");
                    // if ($response->status() !== 200) {
                    //     throw new Exception("Failed to Send Document penjaminan Syarat Umum " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    // }
                    // $bodyResponse = json_decode($response->body(), true);
                    // if ($bodyResponse['Success'] !== true) {
                    //     throw new Exception("Failed to Send penjaminan Syarat Umum " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    // }
                }

                // check if lampiran id is in list of lampiran syarat khusus
                if (in_array(strtolower($bin->lampiran_id), $listSyaratKhusus)) {
                    $payloadDocument = [
                        "NIK" => $fileNik,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Syarat Khusus",
                        "DataBase64" => $binS3Base64
                    ];
                    Log::info('Sending lampiran ' .  $namaJenis . ' Syarat Khusus to Creatio', [
                        'endpoint' => 'PenjaminanService@approvePenjaminanKUR',
                        'time' => now()->toDateTimeString(),
                    ]);
                    // $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    // LOG::info("check inside looping {$response} {$namaJenis} {$debiturFileName}");
                    // if ($response->status() !== 200) {
                    //     throw new Exception("Failed to Send Document penjaminan Syarat Khusus " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    // }
                    // $bodyResponse = json_decode($response->body(), true);
                    // if ($bodyResponse['Success'] !== true) {
                    //     throw new Exception("Failed to Send penjaminan Syarat Khusus " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    // }
                }
            }
            Log::info('END iterate lampiran Kredit Usaha Rakyat (KUR)', [
                'endpoint' => 'PenjaminanService@approvePenjaminanKUR',
                'time' => now()->toDateTimeString(),
            ]);
            // dd("upload done");
            // (END) 2. temporarily remarked (send lampiran to core creatio)
            // temporarily remark send notif to core
            // $notifCreatioPayload = [
            //     "Title" => "Mitra Portal Notification",
            //     "Subject" => "Register Penjaminan Success",
            //     // "Contact" => $request->nama
            //     "Contact" => "Supervisor"
            // ];
            // $notifRes = $svcPenjCreatio->request('post', '/0/rest/Notification/SendNotification', $notifCreatioPayload);
            // $notifResBody = json_decode($notifRes->body(), true);
            // (END) temporarily remark send notif to core

            // $noTelp = $penjaminan->no_telp;
            // if (str_starts_with($noTelp, '0')) {
            //     $noTelp = '62' . substr($noTelp, 1); 
            // }
            // LOG::info("reaching final update for penjaminan {$trx_no} with phone number {$noTelp}");

            PenjaminanTransaction::where('trx_no', $trx_no)->update([
                'status_sync_creatio' => 1,
                'trx_status' => $finalTrxStatus,
                'updated_by_id' => $user_id,
                'updated_by_name' => $user_name,
                'updated_at' => $nowJakarta,
            ]);

            PenjaminanFlow::insert([
                'trx_no' => $trx_no,
                'trx_status' => $finalTrxStatus,
                'created_at' => $nowJakarta,
                'created_by_id' => auth('sanctum')->user()->user_id,
                'created_by_name' => auth('sanctum')->user()->name,
                'status' => true
            ]);

            NotifMitra::create([
                'mitra_user_id' => auth('sanctum')->user()->user_id,
                'title' => "Mitra Portal - Penjaminan Kredit Usaha Rakyat (KUR)",
                'message' => "Status penjaminan KUR dengan nomor " . $trx_no . " menjadi " . "Submitted",
            ]);

            DB::commit();
            Log::info("Penjaminan KUR {$trx_no} approved successfully.");
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Error approving penjaminan KUR {$trx_no}: {$e->getMessage()}");
            throw $e;
        }
    }

    public function approvePenjaminanKonstruksi(string $trx_no, ?string $user_id = null, ?string $user_name = null, ?string $sources = null)
    {
        ini_set('max_execution_time', 0);
        try {
            DB::beginTransaction();
            Log::info('User ID for approval: ' . $user_id . ' Name: ' . $user_name);
            $penjaminan = PenjaminanTransaction::join('multiguna_trx_kreditkonstruksi as kut', 'transaction_penjaminan_header.trx_no', '=', 'kut.trx_no')
                ->where('transaction_penjaminan_header.trx_no', $trx_no)
                ->where('transaction_penjaminan_header.status_sync_creatio', 0)
                ->first();
            if (!$penjaminan) {
                throw new Exception("Penjaminan {$trx_no} not found or already synced.");
            }
            Log::info('regist KKPBJ', [
                'endpoint' => 'PenjaminanService@approvePenjaminanKonstruksi',
                'time' => now()->toDateTimeString(),
            ]);
            $debiturs = TrxDebiturKonstruksi::where('id_multiguna_konstruksi', $penjaminan->id_multiguna_konstruksi)->get();

            $allCAC = $debiturs->every(fn($d) => ($d->jenis_penjaminan ?? null) === 'CAC');
            $allCBC = $debiturs->every(fn($d) => ($d->jenis_penjaminan ?? null) === 'CBC');
            $finalTrxStatus = null;
            if ((!$allCAC && !$allCBC) || ($allCAC)) {
                $finalTrxStatus = 'WFP';
            } elseif ($allCBC) {
                $finalTrxStatus = 'S';
            }
            $institutionIds = $debiturs->pluck('institution_id')->filter()->unique()->values();
            $institutionsById = Institution::whereIn('institution_id', $institutionIds)
                ->get()
                ->keyBy('institution_id');
            $debitursArray = $debiturs->map(function ($d) use ($institutionsById) {
                $arr = $d->toArray();
                $arr['nik'] = $d->nik;
                $arr['birth_date'] = $institutionsById[$d->institution_id]->birth_date ?? null;
                $arr['gender'] = $institutionsById[$d->institution_id]->gender ?? null;
                return $arr;
            })->values()->all();

            $dataMitra = Mitra::where('mitra_id', $penjaminan->mitra_id)->first();
            $nowJakarta = Carbon::now('Asia/Jakarta');

            // $creatioPayload = [
            //     "PermohonanPenjaminanMultiguna" => [
            //         [
            //             "CaraBayar" => $penjaminan->sp_split == true ? 'Installment' : 'Full Payment',
            //             "PKS" => $penjaminan->pks_number ?? '0018/JJ-1/KBP.00.06/PIK/2025',
            //             "TanggalSuratPermohonan" => $penjaminan->tanggal_surat_permohonan ?? '2025-10-27',
            //             "NomorSuratPermohonan" => $penjaminan->no_surat_permohonan ?? '1000023-Testing003',
            //             "MitraId" => 'MDR',
            //             "TarifPercentage" => (int)  $penjaminan->fee_base_number ?? 2.00,
            //             "NomorPermohonan" => $penjaminan->no_surat_permohonan ?? 'Testing003',
            //             "BankCabang" => $penjaminan->bank_code . ' - ' . $penjaminan->bank_name ?? 'BANK MANDIRI - Mandiri Sudirman',
            //             "FeeBasePercentage" => (int)  $penjaminan->fee_base_percentage ?? 10.00,
            //             "TeksPercentagePenjaminandiSP" => (int) $penjaminan->text_certified ?? 70.00,
            //             "ListDebitur" => collect($debitursArray)->map(function (array $d) use ($nowJakarta) {
            //                 return [
            //                     "Name" => $d['debitur_name'] ?? 'Doricky',
            //                     "Nik" => $d['nik'] ?? '1231231231231231',
            //                     "NamaMakhfulAnhu" => $d['jenis_makful_anhu'] ?? 'Testing',
            //                     "TanggalLahir" => $d['birth_date'] ?? '1995-08-29',
            //                     "NilaiKafalah" => (int)  $d['nilai_kafalah'] ?? 70000000,
            //                     "TanggalRealisasi" => $d['tanggal_realisasi'] ?? '2028-08-29',
            //                     "NilaiAgunan" => (int) $d['nilai_agunan'] ?? 10000000,
            //                     "JenisAgunan" => $d['jenis_agunan'] ?? 'IJK',
            //                     "JenisMakhfulAnhu" => $d['jenis_makful_anhu'] ?? 'Testing',
            //                     "InstansiPekerjaanTerjamin" => $d['institution_name'] ?? 'Testing',
            //                     "JwBulan" => (int) $d['jw_bulan'] ?? 12,
            //                     "JenisKelamin" => (int) $d['gender'] == "L" ? "Laki-Laki" : "Perempuan",
            //                     "MarginBagiHasilUjrahTahun" => (int) $d['margin'] ?? 120000,
            //                     "JumlahDana" => (int) $d['plafond_pembiayaan'] ?? 100000,
            //                     "PlafonPembiayaan" => (int) $d['plafond_pembiayaan'] ?? 10000000,
            //                     "LoanNumber" => $d['loan_number'] ?? '1231203',
            //                     "TanggalJatuhTempo" => $d['tanggal_jatuh_tempo'] ?? '2028-08-29',
            //                     "PenggunaanPembiayaan" => $d['penggunaan_pembiayaan'] ?? 'Testing',
            //                     "TenagaKerja" => $d['tenaga_kerja'] ?? 'Testng',
            //                     "Tanggal" => $nowJakarta->toDateString(),
            //                     "Tenor" => (int) $d['jw_bulan'] ?? 12,
            //                 ];
            //             })->values()->all(),
            //         ]
            //     ]
            // ];
            // $svcPenjCreatio = new CreatioService();
            // $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/RegistrasiMultiguna', $creatioPayload);
            // // dd($response);
            // if ($response->status() !== 200) {
            //     throw new Exception("Failed to register Penjaminan Multiguna to Core Creatio API with status: " . $response->status());
            // }

            // $bodyResponse = json_decode($response->body(), true);
            // if ($bodyResponse['Success'] !== true) {
            //     throw new Exception("Failed to register Penjaminan Multiguna to Core Creatio API with message: " . $bodyResponse['Message']);
            // }
            // if(!is_array($bodyResponse['Data']) || empty($bodyResponse['Data']))
            // {
            //     throw new Exception('Failed to get response from register Penjaminan Multiguna to Core API: empty or unreadable Data from response.');
            // }
            // $responsePayload = $bodyResponse['Data'][0];
            // if(!is_array($responsePayload["Detail"]))
            // {
            //     throw new Exception('Failed to get response from register Penjaminan Multiguna to Core API: unreadable Detail array from response.');
            // }
            // test using dummy response first
            // if($dummyRegistResponse['Success'] !== true) {
            //     throw new Exception("Failed to register Penjaminan Multiguna to Core Creatio API with message: " . $bodyResponse['Message']);
            // }
            // if(!is_array($dummyRegistResponse['Data']) || empty($dummyRegistResponse['Data']))
            // {
            //     throw new Exception('Failed to get response from register Penjaminan Multiguna to Core API: empty or unreadable Data from response.');
            // }
            // $responsePayload = $dummyRegistResponse['Data'][0];
            // if(!is_array($responsePayload["Detail"]))
            // {
            //     throw new Exception('Failed to get response from register Penjaminan Multiguna to Core API: unreadable Detail array from response.');
            // }

            // $key = base64_decode(config('services.secure.key'));
            // $debiturQueryData = MultigunaDebitur::where('multiguna_trx_id', $penjaminan->id_multiguna)
            //     ->select('id_trx_debitur', 'no_sp_detail', 'debitur_name', 'debitur_address', 'loan_number', 'nik')
            //     ->get()->map(function($query) use ($key) {
            //         $nikDecrypt = AesHelper::decrypt($query->nik, $key);
            //         $result = [
            //             'id_trx_debitur' => $query->id_trx_debitur,
            //             'no_sp_detail' => $query->no_sp_detail,
            //             'debitur_name' => $query->debitur_name,
            //             'debitur_address' => $query->debitur_address,
            //             'loan_number' => $query->loan_number,
            //             'nik' => $nikDecrypt
            //         ];
            //         return $result;
            //     });
            // $queryCollectArr = collect($debiturQueryData)->toArray();

            Log::info('END regist KKPBJ', [
                'endpoint' => 'PenjaminanService@approvePenjaminanKonstruksi',
                'time' => now()->toDateTimeString(),
            ]);

            // 1. temporarily remarked (send lampiran to core creatio)
            $binaryLampiran = PenjaminanLampiranDtl::where('trx_no', $trx_no)
                ->select(
                    'trx_no',
                    'lampiran_id',
                    'file_info',
                    'mime_type'
                )->cursor();
            $lampiranCodeList = array_unique($binaryLampiran->pluck('lampiran_id')->toArray());
            $lampiranJenisMapping = MappingValue::where('key', 'lampiran')
                ->whereIn('value', $lampiranCodeList)
                ->select('value', 'option3')
                ->get();

            $listPerorangan = ['ktp', 'npywp'];
            $listSyaratUmum = ['app', 'npwp', 'ppp', 'siujk'];
            $listSyaratKhusus = ['nib', 'rdpj'];
            // (END) 1. temporarily remarked (send lampiran to core creatio)

            // $mapJenisLampiran = [
            //     [
            //         "type" => "Perorangan",
            //         "list" => $listPerorangan
            //     ],
            //     [
            //         "type" => "Syarat Umum",
            //         "list" => $listSyaratUmum
            //     ],
            //     [
            //         "type" => "Syarat Khusus",
            //         "list" => $listSyaratKhusus
            //     ]
            // ];
            // dd($binaryLampiran);


            Log::info('iterate lampiran Kredit Usaha', [
                'endpoint' => 'PenjaminanService@approvePenjaminanKreditUsaha',
                'time' => now()->toDateTimeString(),
            ]);

            // 2. temporarily remarked (send lampiran to core creatio)
            foreach ($binaryLampiran as $bin) {
                Log::info('Get NIK and base64 lampiran ' .  $bin->lampiran_id, [
                    'endpoint' => 'PenjaminanService@approvePenjaminanKonstruksi',
                    'time' => now()->toDateTimeString(),
                ]);
                $binFileNameParse = explode('/', $bin->file_info);
                $debiturFileName = $binFileNameParse[count($binFileNameParse) - 1];
                $fileNameParse = explode('-', $debiturFileName);
                $fileNik = $fileNameParse[0];
                $binS3Content = Storage::disk('s3')->get($bin->file_info);
                $binS3Base64 = base64_encode($binS3Content);
                $jenisByLampiranId = $lampiranJenisMapping
                    ->firstWhere('value', strtolower($bin->lampiran_id));
                $namaJenis = $jenisByLampiranId ? $jenisByLampiranId->option3 : "";
                LOG::info("check jenis dokumen {$jenisByLampiranId}");
                // check if lampiran id is in list of lampiran perorangan
                if (in_array(strtolower($bin->lampiran_id), $listPerorangan)) {
                    $payloadDocument = [
                        "NIK" => $fileNik,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Perorangan",
                        "DataBase64" => $binS3Base64
                    ];
                    Log::info('Sending lampiran ' .  $namaJenis . ' Perorangan to Creatio', [
                        'endpoint' => 'PenjaminanService@approvePenjaminanKreditUsaha',
                        'time' => now()->toDateTimeString(),
                    ]);
                    // $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    // LOG::info("check inside looping {$response} {$namaJenis} {$debiturFileName}");
                    // if ($response->status() !== 200) {
                    //     throw new Exception("Failed to Send Document penjaminan Perorangan " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    // }
                    // $bodyResponse = json_decode($response->body(), true);
                    // if ($bodyResponse['Success'] !== true) {
                    //     throw new Exception("Failed to Send penjaminan Perorangan " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    // }
                }
                // check if lampiran id is in list of lampiran syarat umum
                if (in_array(strtolower($bin->lampiran_id), $listSyaratUmum)) {
                    $payloadDocument = [
                        "NIK" => $fileNik,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Syarat Umum",
                        "DataBase64" => $binS3Base64
                    ];
                    Log::info('Sending lampiran '  .  $namaJenis . ' Syarat Umum to Creatio', [
                        'endpoint' => 'PenjaminanService@approvePenjaminanKreditUsaha',
                        'time' => now()->toDateTimeString(),
                    ]);
                    // $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    // LOG::info("check inside looping {$response} {$namaJenis} {$debiturFileName}");
                    // if ($response->status() !== 200) {
                    //     throw new Exception("Failed to Send Document penjaminan Syarat Umum " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    // }
                    // $bodyResponse = json_decode($response->body(), true);
                    // if ($bodyResponse['Success'] !== true) {
                    //     throw new Exception("Failed to Send penjaminan Syarat Umum " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    // }
                }
                // check if lampiran id is in list of lampiran syarat khusus
                if (in_array(strtolower($bin->lampiran_id), $listSyaratKhusus)) {
                    $payloadDocument = [
                        "NIK" => $fileNik,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Syarat Khusus",
                        "DataBase64" => $binS3Base64
                    ];
                    Log::info('Sending lampiran ' .  $namaJenis . ' Syarat Khusus to Creatio', [
                        'endpoint' => 'PenjaminanService@approvePenjaminanKreditUsaha',
                        'time' => now()->toDateTimeString(),
                    ]);
                    // $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    // LOG::info("check inside looping {$response} {$namaJenis} {$debiturFileName}");
                    // if ($response->status() !== 200) {
                    //     throw new Exception("Failed to Send Document penjaminan Syarat Khusus " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    // }
                    // $bodyResponse = json_decode($response->body(), true);
                    // if ($bodyResponse['Success'] !== true) {
                    //     throw new Exception("Failed to Send penjaminan Syarat Khusus " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    // }
                }
            }
            Log::info('END iterate lampiran Konstruksi', [
                'endpoint' => 'PenjaminanService@approvePenjaminanKonstruksi',
                'time' => now()->toDateTimeString(),
            ]);
            // dd("upload done");
            // (END) 2. temporarily remarked (send lampiran to core creatio)
            // temporarily remark send notif to core
            // $notifCreatioPayload = [
            //     "Title" => "Mitra Portal Notification",
            //     "Subject" => "Register Penjaminan Success",
            //     // "Contact" => $request->nama
            //     "Contact" => "Supervisor"
            // ];
            // $notifRes = $svcPenjCreatio->request('post', '/0/rest/Notification/SendNotification', $notifCreatioPayload);
            // $notifResBody = json_decode($notifRes->body(), true);
            // (END) temporarily remark send notif to core

            // $noTelp = $penjaminan->no_telp;
            // if (str_starts_with($noTelp, '0')) {
            //     $noTelp = '62' . substr($noTelp, 1); 
            // }
            // LOG::info("reaching final update for penjaminan {$trx_no} with phone number {$noTelp}");

            PenjaminanTransaction::where('trx_no', $trx_no)->update([
                'status_sync_creatio' => 1,
                'trx_status' => $finalTrxStatus,
                'updated_by_id' => $user_id,
                'updated_by_name' => $user_name,
                'updated_at' => $nowJakarta,
            ]);

            PenjaminanFlow::insert([
                'trx_no' => $trx_no,
                'trx_status' => $finalTrxStatus,
                'created_at' => $nowJakarta,
                'created_by_id' => auth('sanctum')->user()->user_id,
                'created_by_name' => auth('sanctum')->user()->name,
                'status' => true
            ]);

            NotifMitra::create([
                'mitra_user_id' => auth('sanctum')->user()->user_id,
                'title' => "Mitra Portal - Penjaminan KKPBJ",
                'message' => "Status penjaminan kkpbj dengan nomor " . $trx_no . " menjadi " . "Submitted",
            ]);

            DB::commit();
            Log::info("Penjaminan KKPBJ {$trx_no} approved successfully.");
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Error approving penjaminan Konstruksi {$trx_no}: {$e->getMessage()}");
            throw $e;
        }
    }

    public function approveCSTBPenjaminan(string $trx_no, string $user_id = null, string $user_name = null, string $sources = null)
    {
        try {
            DB::beginTransaction();
            Log::info('User ID for CSTB approval: ' . $user_id . ' Name: ' . $user_name);

            //
            $penjaminan = PenjaminanTransaction::join('custom_bond_transaction as mt', 'transaction_penjaminan_header.trx_no', '=', 'mt.trx_no')
                ->where('transaction_penjaminan_header.trx_no', $trx_no)
                ->where('transaction_penjaminan_header.status_sync_creatio', 0)
                ->first();

            if (!$penjaminan) {
                throw new Exception("Penjaminan {$trx_no} not found or already synced.");
            }

            $institution = Institution::where('id', $penjaminan->id_institution)->first();
            if (!$institution) {
                throw new Exception("institution id {$penjaminan->id_institution} not found.");
            }

            $mitra_id = auth('sanctum')->user()->mitra_id;

            $mitra = TenantMitra::where('mitra_id', $mitra_id)
                ->select('alias', 'is_syariah', 'is_conventional')
                ->first();


            Log::info('regist custom bond', [
                'endpoint' => 'PenjaminanService@approveCSTBPenjaminan',
                'time' => now()->toDateTimeString(),
            ]);
            $detail = [
                "NomorPermohonan" => $penjaminan->no_surat_permohonan ?? "XTTR10315",
                "NomorSuratPermohonan" =>  $penjaminan->no_surat_permohonan ?? "NSP-2025-001",
                "NoSuratPermohonan" =>  $penjaminan->no_surat_permohonan ?? "NSP-2025-001",
                "NamaPrincipal" => $penjaminan->principal_name ?? "PT Maju Jaya Abadi",
                "PenerimaPenjaminan" => $institution->full_name ?? "",
                "NamaProyek" => $penjaminan->project_name ?? "Proyek Pembangunan Gedung Perkantoran",
                "NilaiProyek" => (int) ($penjaminan->project_amount ?? 70000000),
                "NilaiBond" => (int) ($penjaminan->amount_bond ?? 50000000),
                "NilaiBondPersentase" => $penjaminan->bond_percentage ?? 70,
                "TanggalSuratPerjanjian" => $penjaminan->tgl_surat_perjanjian ?? "2025-10-27",
                "TanggalSuratPermohonan" => $penjaminan->tanggal_surat_permohonan ?? "2025-10-27",
                "TanggalSuratBAST" => $penjaminan->bast_date ?? "2025-10-27",
                "PeriodeAwalBerlaku" => $penjaminan->start_period_date ?? "2025-08-08",
                "PeriodeAkhirBerlaku" => $penjaminan->end_period_date ?? "2028-08-29",
                "NoSuratBAST" => $penjaminan->no_surat_bast ?? "12931232",
                "NoSuratPerjanjian" => $penjaminan->no_surat_perjanjian ?? "SP-001/BSI/X/2025",
                "IsBAST" => ($penjaminan->is_bast == "1"),
                "JangkaWaktu" => 12,
                "TarifPercentage" => (int) ($penjaminan->tarif_percentage ?? 2.00),
                "BiayaAdministrasi" => (int) ($penjaminan->administrative_amount ?? 12000),
                "IJP" => (int) ($penjaminan->ijp_amount ?? 20000000),
                "BiayaMaterai" => (int) ($penjaminan->stamp_amount ?? 12000),
                "PlafondKredit" => 0,
                "JenisBond" => $penjaminan->jenis_bond_description ?? "Performance Bond",
                "SkemaPenalty" => $penjaminan->skema_penalty ?? "Flat",
                "JenisPersyaratan" => $penjaminan->jenis_persyaratan ?? "Persyaratan 1",
                "Sektor" => $penjaminan->sektor ?? "Konstruksi",
                "JenisSuratPerjanjian" => $penjaminan->jenis_surat_perjanjian ?? "SPK",
                "Provinsi" => $penjaminan->province ?? "DKI Jakarta",
                "NamaObligee" => $penjaminan->obligee_name ?? "PT Pemilik Proyek",
                "IsDeposit" => ((int) $penjaminan->is_deposit === 1),
                "BankCabang" => "",
                "PKS" => "",
                "MitraId" => $penjaminan->mitra_id ?? "MDR",
                "NamaJenisDokumen" => $penjaminan->document_name ?? "Test",
                "NomorJenisDokumen" => $penjaminan->document_number ?? "Test",
                "TanggalSuratJenisDokumen" => $penjaminan->document_date ?? "2028-08-29",
                "PercentageCogar" => 0,
                "MitraCogar" => "",
                "JenisCogar" => "",
                "IsConven" => (bool)$mitra->is_conventional  ? true : false,
            ];
            if ((int) $penjaminan->is_deposit !== 1) {
                $detail["CaraBayar"] = ($penjaminan->sp_split == true) ? "Installment" : "Full Payment";
            } else {
                unset($detail["CaraBayar"]);
            }
            $creatioPayload = [
                "PermohonanPenjaminanCustomBond" => [
                    $detail
                ]
            ];
            $svcPenjCreatio = new CreatioService();
            $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/RegistrasiCustomBond', $creatioPayload);

            if ($response->status() !== 200) {
                throw new Exception("Failed to register Penjaminan Custom Bond to Core Creatio API with status: " . $response->status());
            }

            $bodyResponse = json_decode($response->body(), true);
            if ($bodyResponse['Success'] !== true) {
                throw new Exception("Failed to register Penjaminan Custom Bond to Core Creatio API with message: " . $bodyResponse['Message']);
            }

            Log::info('END regist custom bond', [
                'endpoint' => 'PenjaminanService@approveCSTBPenjaminan',
                'time' => now()->toDateTimeString(),
            ]);

            // ============================================================
            // UPLOAD DOKUMEN/GAMBAR DARI S3
            // ============================================================

            Log::info('START upload documents for Custom Bond', [
                'trx_no' => $trx_no,
                'time' => now()->toDateTimeString(),
            ]);

            // Ambil lampiran dengan query untuk mendapatkan versi terbaru
            $lampiran = PenjaminanLampiranDtl::select('trx_no', 'lampiran_id', 'file_name', 'file_info', 'version', 'mime_type')
                ->where('trx_no', $penjaminan->trx_no)
                ->whereRaw("
                NOT EXISTS (
                    SELECT 1
                    FROM penjaminan_lampiran_dtl AS p2
                    WHERE p2.trx_no = penjaminan_lampiran_dtl.trx_no
                    AND p2.lampiran_id = penjaminan_lampiran_dtl.lampiran_id
                    AND SUBSTRING(p2.file_name, LOCATE('-', p2.file_name, LOCATE('-', p2.file_name) + 1) + 1, LENGTH(p2.file_name)) > SUBSTRING(penjaminan_lampiran_dtl.file_name, LOCATE('-', penjaminan_lampiran_dtl.file_name, LOCATE('-', penjaminan_lampiran_dtl.file_name) + 1) + 1, LENGTH(penjaminan_lampiran_dtl.file_name))
                )
            ")
                ->cursor();

            // Ambil unique lampiran IDs untuk mapping
            $lampiranCodeList = array_unique($lampiran->pluck('lampiran_id')->toArray());

            // Mapping jenis dokumen dari database
            $lampiranJenisMapping = MappingValue::where('key', 'lampiran')
                ->whereIn('value', $lampiranCodeList)
                ->select('value', 'option3')
                ->get();

            // Definisi kategori dokumen untuk Custom Bond
            $listPerorangan = ['ktp', 'npywp'];
            $listSyaratUmum = ['app', 'npwp', 'ppp', 'siujk'];
            $listSyaratKhusus = ['nib', 'rdpj'];

            $uploadedCount = 0;

            // Loop setiap lampiran
            foreach ($lampiran as $bin) {
                try {
                    Log::info('Processing lampiran: ' . $bin->lampiran_id, [
                        'endpoint' => 'PenjaminanService@approveCSTBPenjaminan',
                        'file_name' => $bin->file_name,
                        'time' => now()->toDateTimeString(),
                    ]);

                    $fileInfo = json_decode($bin->file_info, true);
                    if (json_last_error() === JSON_ERROR_NONE && isset($fileInfo['path'])) {
                        $debiturFileName = basename($fileInfo['path']);
                    } else {
                        $debiturFileName = basename($bin->file_info);
                    }
                    // Ambil file dari S3 dan convert ke Base64

                    $binS3Content = Storage::disk('s3')->get($fileInfo['path']);
                    $binS3Base64 = base64_encode($binS3Content);

                    // Cari mapping jenis dokumen
                    $jenisByLampiranId = $lampiranJenisMapping->firstWhere('value', strtolower($bin->lampiran_id));
                    $namaJenis = $jenisByLampiranId ? $jenisByLampiranId->option3 : "";

                    Log::info("Jenis dokumen mapping", [
                        'lampiran_id' => $bin->lampiran_id,
                        'nama_jenis' => $namaJenis
                    ]);

                    // Upload dokumen berdasarkan kategori

                    // 1. Perorangan
                    if (in_array(strtolower($bin->lampiran_id), $listPerorangan)) {
                        $payloadDocument = [
                            "NIK" => null,
                            "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                            "NamaDokumen" => $debiturFileName,
                            "JenisDokumen" => $namaJenis,
                            "TipeDokumen" => "Perorangan",
                            "DataBase64" => $binS3Base64
                        ];

                        Log::info('Sending lampiran ' . $namaJenis . ' Perorangan to Creatio', [
                            'endpoint' => 'PenjaminanService@approveCSTBPenjaminan',
                            'time' => now()->toDateTimeString(),
                        ]);

                        $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);

                        Log::info("Upload response", [
                            'status' => $response->status(),
                            'jenis' => $namaJenis,
                            'file' => $debiturFileName
                        ]);

                        if ($response->status() !== 200) {
                            throw new Exception("Failed to Send Document penjaminan Perorangan " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                        }

                        $bodyResponse = json_decode($response->body(), true);
                        if ($bodyResponse['Success'] !== true) {
                            throw new Exception("Failed to Send penjaminan Perorangan " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                        }

                        $uploadedCount++;
                    }

                    // 2. Syarat Umum
                    if (in_array(strtolower($bin->lampiran_id), $listSyaratUmum)) {
                        $payloadDocument = [
                            "NIK" => null,
                            "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                            "NamaDokumen" => $debiturFileName,
                            "JenisDokumen" => $namaJenis,
                            "TipeDokumen" => "Syarat Umum",
                            "DataBase64" => $binS3Base64
                        ];
                        //dd($payloadDocument);
                        Log::info('Sending lampiran ' . $namaJenis . ' Syarat Umum to Creatio', [
                            'endpoint' => 'PenjaminanService@approveCSTBPenjaminan',
                            'time' => now()->toDateTimeString(),
                        ]);

                        $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);

                        Log::info("Upload response", [
                            'status' => $response->status(),
                            'jenis' => $namaJenis,
                            'file' => $debiturFileName
                        ]);

                        if ($response->status() !== 200) {
                            throw new Exception("Failed to Send Document penjaminan Syarat Umum " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                        }

                        $bodyResponse = json_decode($response->body(), true);
                        if ($bodyResponse['Success'] !== true) {
                            throw new Exception("Failed to Send penjaminan Syarat Umum " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                        }

                        $uploadedCount++;
                    }

                    // 3. Syarat Khusus
                    if (in_array(strtolower($bin->lampiran_id), $listSyaratKhusus)) {
                        $payloadDocument = [
                            "NIK" => null,
                            "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                            "NamaDokumen" => $debiturFileName,
                            "JenisDokumen" => $namaJenis,
                            "TipeDokumen" => "Syarat Khusus",
                            "DataBase64" => $binS3Base64
                        ];

                        Log::info('Sending lampiran ' . $namaJenis . ' Syarat Khusus to Creatio', [
                            'endpoint' => 'PenjaminanService@approveCSTBPenjaminan',
                            'time' => now()->toDateTimeString(),
                        ]);

                        $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);

                        Log::info("Upload response", [
                            'status' => $response->status(),
                            'jenis' => $namaJenis,
                            'file' => $debiturFileName
                        ]);

                        if ($response->status() !== 200) {
                            throw new Exception("Failed to Send Document penjaminan Syarat Khusus " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                        }

                        $bodyResponse = json_decode($response->body(), true);
                        if ($bodyResponse['Success'] !== true) {
                            throw new Exception("Failed to Send penjaminan Syarat Khusus " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                        }

                        $uploadedCount++;
                    }
                } catch (Exception $docException) {
                    Log::error("Error uploading document: " . $docException->getMessage(), [
                        'lampiran_id' => $bin->lampiran_id,
                        'file_name' => $bin->file_name ?? 'unknown'
                    ]);
                    throw $docException; // Re-throw untuk rollback
                }
            }

            Log::info('END upload documents for Custom Bond', [
                'trx_no' => $trx_no,
                'uploaded_count' => $uploadedCount,
                'time' => now()->toDateTimeString(),
            ]);

            // ============================================================
            // END: UPLOAD DOKUMEN/GAMBAR
            // ============================================================

            // Update status transaksi
            PenjaminanTransaction::where('trx_no', $trx_no)->update([
                'status_sync_creatio' => 1,
                'trx_status' => 'S',
                'updated_by_id' => $user_id,
                'updated_by_name' => $user_name,
                'updated_at' => now(),
            ]);

            // Insert flow
            PenjaminanFlow::insert([
                'trx_no' => $trx_no,
                'trx_status' => 'S',
                'created_at' => now(),
                'updated_at' => now(),
                'created_by_id' => auth('sanctum')->user()->user_id,
                'created_by_name' => auth('sanctum')->user()->name,
                'status' => true
            ]);

            // Create notification
            NotifMitra::create([
                'mitra_user_id' => auth('sanctum')->user()->user_id,
                'title' => "Mitra Portal - Penjaminan Custom Bond Approval",
                'message' => "Status Penjaminan dengan nomor " . $trx_no . " menjadi " . "Approved",
            ]);

            DB::commit();
            Log::info("Penjaminan Custom Bond {$trx_no} approved successfully with {$uploadedCount} documents uploaded.");
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Error approving penjaminan Custom Bond {$trx_no}: {$e->getMessage()}");
            throw $e;
        }
    }

    public function approveSuretyBondPenjaminan(string $trx_no, string $user_id = null, string $user_name = null, string $sources = null)
    {
        try {
            ini_set('max_execution_time', 0);
            ini_set('memory_limit', '-1');
            $key = base64_decode(config('services.secure.key'));
            DB::beginTransaction();
            Log::info('User ID for Surety Bond approval: ' . $user_id . ' Name: ' . $user_name);
            $penjaminan = PenjaminanTransaction::join('surety_bond_transaction as st', 'transaction_penjaminan_header.trx_no', '=', 'st.trx_no')
                ->where('transaction_penjaminan_header.trx_no', $trx_no)
                ->where('transaction_penjaminan_header.status_sync_creatio', 0)
                ->first();
            // dd($penjaminan);
            if (!$penjaminan) {
                throw new Exception("Penjaminan {$trx_no} not found or already synced.");
            }
            $institutionData = Institution::where('id', $penjaminan->id_institution)
                ->select('full_name', 'id_number')->first();

            if (!$institutionData) {
                throw new Exception("Penjaminan {$trx_no} does not have institution data.");
            }

            $mitra_id = auth('sanctum')->user()->mitra_id;

            $mitra = TenantMitra::where('mitra_id', $mitra_id)
                ->select('alias', 'is_syariah', 'is_conventional')
                ->first();


            $suretyBondPayload = [
                "NomorPermohonan" => $penjaminan->no_surat_permohonan ?? 'XTTR10314',
                "NomorSuratPermohonan" => $penjaminan->no_surat_permohonan ?? 'NSP-2025-001',
                "NoSuratPermohonan" => $penjaminan->no_surat_permohonan ?? 'NSP-2025-001',
                "NamaPrincipal" => $penjaminan->principal_name,
                "PenerimaPenjaminan" => $institutionData->full_name,
                "NamaProyek" => $penjaminan->project_name ?? 0,
                "NilaiProyek" => (int) $penjaminan->project_amount ?? 0,
                "NilaiBond" => (int) $penjaminan->amount_bond ?? 0,
                "NilaiBondPersentase" => (float) $penjaminan->bond_percentage,
                "TanggalSuratPerjanjian" => $penjaminan->tgl_surat_perjanjian,
                "TanggalSuratPermohonan" => $penjaminan->tanggal_surat_permohonan,
                "TanggalSuratBAST" => $penjaminan->is_bast == 1 ? $penjaminan->bast_date : null,
                "PeriodeAwalBerlaku" => $penjaminan->start_period_date,
                "PeriodeAkhirBerlaku" => $penjaminan->end_period_date,
                "NoSuratBAST" => $penjaminan->is_bast == 1 ? $penjaminan->no_surat_bast : null,
                "NoSuratPerjanjian" => $penjaminan->tgl_surat_perjanjian,
                "IsBAST" => $penjaminan->is_bast == 1 ? true : false,
                "JangkaWaktu" => $penjaminan->total_day,
                "JangkaWaktuHari" => $penjaminan->total_day ?? 0,
                "TarifPercentage" => (float) $penjaminan->tarif_percentage ?? 0,
                "BiayaAdministrasi" => (int) $penjaminan->administrative_amount ?? 0,
                "IJP" => (int) $penjaminan->ijp_amount ?? 0,
                "BiayaMaterai" => (int) $penjaminan->stamp_amount ?? 0,
                "PlafondKredit" => 0,
                "JenisBond" => $penjaminan->jenis_bond_description,
                // "JenisBond"=> "Performance Bond",
                "SkemaPenalty" => $penjaminan->skema_penalty,
                "JenisPersyaratan" => $penjaminan->jenis_persyaratan,
                "Sektor" => $penjaminan->sektor,
                "JenisSuratPerjanjian" => $penjaminan->jenis_surat_perjanjian,
                "Provinsi" => $penjaminan->province,
                "NamaObligee" => $penjaminan->obligee_name,
                "NilaiAgunan" => $penjaminan->agunan_amount,
                "BankCabang" => "",
                "PKS" => "",
                "MitraId" => "MDR",
                "CaraBayar" => $penjaminan->sp_split == true ? 'Installment' : 'Full Payment',
                "IsConven" => (bool)$mitra->is_conventional  ? true : false,
            ];
            // dd($suretyBondPayload);
            $creatioPayload = [
                "PermohonanPenjaminanSuretyBond" => [$suretyBondPayload]
            ];
            $svcCreatio = new CreatioService();
            $response = $svcCreatio->request('post', '/0/rest/PermohonanPenjaminan/RegistrasiSuretyBond', $creatioPayload);
            if ($response->status() !== 200) {
                throw new Exception("Failed to Data Surety Bond to Core Creatio API with status: " . $response->status());
            }
            $bodyResponse = json_decode($response->body(), true);
            if ($bodyResponse['Success'] !== true) {
                throw new Exception("Failed to Data Surety Bond to Core Creatio API with message: " . $bodyResponse['Message']);
            }

            $lampiran = PenjaminanLampiranDtl::select('trx_no', 'lampiran_id', 'file_name', 'file_info', 'version', 'mime_type')
                ->where('trx_no', $penjaminan->trx_no)
                ->whereRaw("
                        NOT EXISTS (
                            SELECT 1
                            FROM penjaminan_lampiran_dtl AS p2
                            WHERE p2.trx_no = penjaminan_lampiran_dtl.trx_no
                            AND p2.lampiran_id = penjaminan_lampiran_dtl.lampiran_id
                            AND SUBSTRING(p2.file_name, LOCATE('-', p2.file_name, LOCATE('-', p2.file_name) + 1) + 1, LENGTH(p2.file_name)) > SUBSTRING(penjaminan_lampiran_dtl.file_name, LOCATE('-', penjaminan_lampiran_dtl.file_name, LOCATE('-', penjaminan_lampiran_dtl.file_name) + 1) + 1, LENGTH(penjaminan_lampiran_dtl.file_name))
                        )
                    ")
                ->cursor();

            $lampiranCodeList = array_unique($lampiran->pluck('lampiran_id')->toArray());
            $lampiranJenisMapping = MappingValue::where('key', 'lampiran')
                ->whereIn('value', $lampiranCodeList)
                ->select('value', 'option3')
                ->get();

            $listPerorangan = ['ktp', 'npywp'];
            $listSyaratUmum = ['app', 'npwp', 'ppp', 'siujk'];
            $listSyaratKhusus = ['nib', 'rdpj'];
            foreach ($lampiran as $bin) {
                Log::info('Get NIK and base64 lampiran ' .  $bin->lampiran_id, [
                    'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                    'time' => now()->toDateTimeString(),
                ]);
                $binFileNameParse = explode('/', $bin->file_info);
                $debiturFileName = $binFileNameParse[count($binFileNameParse) - 1];
                $binS3Content = Storage::disk('s3')->get($bin->file_info);
                $binS3Base64 = base64_encode($binS3Content);
                $jenisByLampiranId = $lampiranJenisMapping
                    ->firstWhere('value', strtolower($bin->lampiran_id));
                $namaJenis = $jenisByLampiranId ? $jenisByLampiranId->option3 : "";
                LOG::info("check jenis dokumen {$jenisByLampiranId}");
                if (in_array(strtolower($bin->lampiran_id), $listPerorangan)) {
                    $payloadDocument = [
                        "NIK" => null,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Perorangan",
                        "DataBase64" => $binS3Base64
                    ];
                    Log::info('Sending lampiran ' .  $namaJenis . ' Perorangan to Creatio', [
                        'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                        'time' => now()->toDateTimeString(),
                    ]);
                    $response = $svcCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    LOG::info("check inside looping {$response} {$namaJenis} {$debiturFileName}");
                    if ($response->status() !== 200) {
                        throw new Exception("Failed to Send Document penjaminan Perorangan " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    }
                    $bodyResponse = json_decode($response->body(), true);
                    if ($bodyResponse['Success'] !== true) {
                        throw new Exception("Failed to Send penjaminan Perorangan " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    }
                }

                // check if lampiran id is in list of lampiran syarat umum
                if (in_array(strtolower($bin->lampiran_id), $listSyaratUmum)) {
                    $payloadDocument = [
                        "NIK" => null,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Syarat Umum",
                        "DataBase64" => $binS3Base64
                    ];
                    Log::info('Sending lampiran '  .  $namaJenis . ' Syarat Umum to Creatio', [
                        'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                        'time' => now()->toDateTimeString(),
                    ]);
                    $response = $svcCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    LOG::info("check inside looping {$response} {$namaJenis} {$debiturFileName}");
                    if ($response->status() !== 200) {
                        throw new Exception("Failed to Send Document penjaminan Syarat Umum " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    }
                    $bodyResponse = json_decode($response->body(), true);
                    if ($bodyResponse['Success'] !== true) {
                        throw new Exception("Failed to Send penjaminan Syarat Umum " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    }
                }

                // check if lampiran id is in list of lampiran syarat khusus
                if (in_array(strtolower($bin->lampiran_id), $listSyaratKhusus)) {
                    $payloadDocument = [
                        // "NIK" => $institutionData->id_number,
                        "NIK" => null,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Syarat Khusus",
                        "DataBase64" => $binS3Base64
                    ];
                    Log::info('Sending lampiran ' .  $namaJenis . ' Syarat Khusus to Creatio', [
                        'endpoint' => 'PenjaminanService@approvePenjaminanMultigunaNew',
                        'time' => now()->toDateTimeString(),
                    ]);
                    $response = $svcCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    LOG::info("check inside looping {$response} {$namaJenis} {$debiturFileName}");
                    if ($response->status() !== 200) {
                        throw new Exception("Failed to Send Document penjaminan Syarat Khusus " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    }
                    $bodyResponse = json_decode($response->body(), true);
                    if ($bodyResponse['Success'] !== true) {
                        throw new Exception("Failed to Send penjaminan Syarat Khusus " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    }
                }
            }
            PenjaminanTransaction::where('trx_no', $trx_no)->update([
                // temporarily change sync status to core because 
                // sending data to core is not available yet
                // 'status_sync_creatio' => 0,
                'status_sync_creatio' => 1,
                'trx_status' => 'S',
                'updated_by_id' => $user_id,
                'updated_by_name' => $user_name,
                'updated_at' => now(),
            ]);

            PenjaminanFlow::insert([
                'trx_no' => $trx_no,
                'trx_status' => 'S',
                'created_at' => now(),
                'updated_at' => now(),
                'created_by_id' => auth('sanctum')->user()->user_id,
                'created_by_name' => auth('sanctum')->user()->name,
                'status' => true
            ]);

            NotifMitra::create([
                'mitra_user_id' => auth('sanctum')->user()->user_id,
                'title' => "Mitra Portal - Penjaminan Surety Bond Approval",
                'message' => "Status Penjaminan dengan nomor " . $trx_no . " menjadi " . "Approved",
            ]);

            DB::commit();
            Log::info("Penjaminan Surety Bond {$trx_no} approved successfully.");
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Error approving penjaminan multi {$trx_no}: {$e->getMessage()}");
            throw $e;
        }
    }

    public function approveKBGPenjaminan(string $trx_no, string $user_id = null, string $user_name = null, string $sources = null)
    {
        try {
            ini_set('max_execution_time', 0);
            ini_set('memory_limit', '-1');
            $key = base64_decode(config('services.secure.key'));
            DB::beginTransaction();
            Log::info('User ID for Kontra Bank Garansi approval: ' . $user_id . ' Name: ' . $user_name);
            $penjaminan = PenjaminanTransaction::join(
                'kbg_transaction as kbg',
                'transaction_penjaminan_header.trx_no',
                '=',
                'kbg.trx_no'
            )
                ->where('transaction_penjaminan_header.trx_no', $trx_no)
                ->where('transaction_penjaminan_header.status_sync_creatio', 0)
                ->first();
            if (!$penjaminan) {
                throw new Exception("Penjaminan {$trx_no} not found or already synced.");
            }
            $institutionData = Institution::where('id', $penjaminan->id_institution)
                ->select('full_name', 'id_number')->first();
            if (!$institutionData) {
                throw new Exception("Penjaminan {$trx_no} does not have institution data.");
            }
            $mitra_id = auth('sanctum')->user()->mitra_id;
            $mitra = TenantMitra::where('mitra_id', $mitra_id)
                ->select('alias', 'is_syariah', 'is_conventional')
                ->first();
            $finalTrxStatus = 'S';
            $KbgPayload = [
                "NomorPermohonan" => $penjaminan->no_surat_permohonan ?? 'XTTR10314',
                "NomorSuratPermohonan" => $penjaminan->no_surat_permohonan ?? 'NSP-2025-001',
                "NoSuratPermohonan" => $penjaminan->no_surat_permohonan ?? 'NSP-2025-001',
                "NamaPrincipal" => $penjaminan->principal_name,
                "PenerimaPenjaminan" => $institutionData->full_name,
                "NamaProyek" => $penjaminan->project_name ?? 0,
                "NilaiProyek" => (int) $penjaminan->project_amount ?? 0,
                "NilaiBond" => (int) $penjaminan->amount_garansi ?? 0,
                "NilaiBondPersentase" => (float) $penjaminan->garansi_percentage,
                "TanggalSuratPerjanjian" => $penjaminan->tgl_surat_perjanjian,
                "TanggalSuratPermohonan" => $penjaminan->tanggal_surat_permohonan,
                "TanggalSuratBAST" => $penjaminan->is_bast == 1 ? $penjaminan->bast_date : null,
                "PeriodeAwalBerlaku" => $penjaminan->start_period_date,
                "PeriodeAkhirBerlaku" => $penjaminan->end_period_date,
                "NoSuratBAST" => $penjaminan->is_bast == 1 ? $penjaminan->no_surat_bast : null,
                "NoSuratPerjanjian" => $penjaminan->tgl_surat_perjanjian,
                "IsBAST" => $penjaminan->is_bast == 1 ? true : false,
                "JangkaWaktu" => $penjaminan->total_day,
                "JangkaWaktuHari" => $penjaminan->total_day ?? 0,
                "TarifPercentage" => (float) $penjaminan->tarif_percentage ?? 0,
                "BiayaAdministrasi" => (int) $penjaminan->administrative_amount ?? 0,
                "IJP" => (int) $penjaminan->ijp_amount ?? 0,
                "BiayaMaterai" => (int) $penjaminan->stamp_amount ?? 0,
                "PlafondKredit" => 0,
                "JenisBond" => $penjaminan->jenis_bond_description,
                // "JenisBond"=> "Performance Bond",
                "SkemaPenalty" => $penjaminan->skema_penalty,
                "JenisPersyaratan" => $penjaminan->jenis_persyaratan,
                "Sektor" => $penjaminan->sektor,
                "JenisSuratPerjanjian" => $penjaminan->jenis_surat_perjanjian,
                "Provinsi" => $penjaminan->province,
                "NamaObligee" => $penjaminan->obligee_name,
                "NilaiAgunan" => $penjaminan->agunan_amount,
                "BankCabang" => "",
                "PKS" => "",
                "MitraId" => "MDR",
                "CaraBayar" => $penjaminan->sp_split == true ? 'Installment' : 'Full Payment',
                "IsConven" => (bool)$mitra->is_conventional  ? true : false,
            ];
            $creatioPayload = [
                "PermohonanPenjaminanSuretyBond" => [$KbgPayload]
            ];
            // $svcCreatio = new CreatioService();
            // $response = $svcCreatio->request('post', '/0/rest/PermohonanPenjaminan/RegistrasiSuretyBond', $creatioPayload);
            // if ($response->status() !== 200) {
            //     throw new Exception("Failed to send Bukti Pembayaran Manual to Core Creatio API with status: " . $response->status());
            // }
            // $bodyResponse = json_decode($response->body(), true);
            // if ($bodyResponse['Success'] !== true) {
            //     throw new Exception("Failed to send Bukti Pembayaran Manual to Core Creatio API with message: " . $bodyResponse['Message']);
            // }

            // skipped send lampiran to core,
            // since there is no API yet to
            // send KBG attachments to core
            PenjaminanTransaction::where('trx_no', $trx_no)
                ->update([
                    'status_sync_creatio' => 1,
                    'trx_status' => $finalTrxStatus,
                    'updated_by_id' => $user_id,
                    'updated_by_name' => $user_name,
                    'updated_at' => now(),
                ]);
            PenjaminanFlow::insert([
                'trx_no' => $trx_no,
                'trx_status' => $finalTrxStatus,
                'created_at' => now(),
                'updated_at' => now(),
                'created_by_id' => auth('sanctum')->user()->user_id,
                'created_by_name' => auth('sanctum')->user()->name,
                'status' => true
            ]);
            NotifMitra::create([
                'mitra_user_id' => auth('sanctum')->user()->user_id,
                'title' => "Mitra Portal - Penjaminan Kontra Bank Garansi Approval",
                'message' => "Status Penjaminan dengan nomor " . $trx_no . " menjadi " . "Approved",
            ]);
            DB::commit();
            Log::info("Penjaminan Kontra Bank Garansi {$trx_no} approved successfully.");
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Error approving penjaminan KBG {$trx_no}: {$e->getMessage()}");
            throw $e;
        }
    }

    public function getPenjaminanPks(object $user)
    {
        $mitra = TenantMitra::where('mitra_id', $user->mitra_id)
            ->select('alias', 'is_syariah', 'is_conventional')
            ->first();
        if ($mitra == null) {
            return response()->json([
                'success' => false,
                'message' => 'Mitra not found.'
            ], 404);
        }
        $pksService = new CreatioService();
        $response = $pksService->request('get', '/0/rest/MasterData/GetPKS', [], [
            'MitraID' => $mitra
        ]);
        if ($response->status() !== 200) {
            throw new Exception("Failed to get data from Core Creatio API with status: " . $response->status());
        }

        $apiResBody = json_decode($response->body(), true);

        if (($apiResBody['Success'] ?? false) !== true) {
            throw new Exception("Failed to get data from Core Creatio API with message: " . ($apiResBody['Message'] ?? 'Unknown error'));
        }

        if (!isset($apiResBody['Data']) || !is_array($apiResBody['Data'])) {
            $apiResBody['Data'] = [];
        }

        if ((bool) $mitra->is_syariah === true) {
            $apiResBody['Data'] = array_values(array_filter($apiResBody['Data'], function ($item) {
                return isset($item['JenisTransaksi']) && $item['JenisTransaksi'] === 'Syariah';
            }));
        } else if ((bool) $mitra->is_conventional === true) {
            $apiResBody['Data'] = array_values(array_filter($apiResBody['Data'], function ($item) {
                return isset($item['JenisTransaksi']) && $item['JenisTransaksi'] === 'Non-Syariah';
            }));
        }
        return $apiResBody;
    }
}
