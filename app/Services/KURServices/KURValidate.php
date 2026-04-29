<?php

namespace App\Services\KURServices;

use Carbon\Carbon;

class KURValidate 
{
    public static function validateDebiturBatch(array $params): array
    {
        $selectedPks = $params['selectedPks'] ?? null;
        $penjaminanPKSData = $params['penjaminanPKSData'] ?? [];
        $dataDebitur = $params['dataDebitur'] ?? [];

        $validateDataPks = $penjaminanPKSData['Data'] ?? [];
        $selectedPKS = null;

        foreach ($validateDataPks as $row) {
            if (($row['Name'] ?? null) === $selectedPks) {
                $selectedPKS = $row;
                break;
            }
        }

        if (!$selectedPKS) {
            return [
                'success' => false,
                'message' => "Nomor PKS {$selectedPks} tidak ditemukan",
                'list_debitur' => $dataDebitur,
                'dataDebitur' => [],
            ];
        }

        $riskPercentage = (float) ($selectedPKS['Macet'] ?? 0);
        $listTerjamin = $selectedPKS['ListTerjamin'] ?? [];
        $maksPlafondByNik = [];
        $nikTerjaminSet = [];

        foreach ($listTerjamin as $t) {
            $nikTerjamin = $t['NIK'] ?? null;
            if (!$nikTerjamin) {
                continue;
            }

            $nikKey = (string) $nikTerjamin;
            $nikTerjaminSet[$nikKey] = true;

            $nilaiMaksimalPlafond = $t['MaksimalNilaiPlafond'] ?? 0;
            if (is_string($nilaiMaksimalPlafond)) {
                $nilaiMaksimalPlafond = preg_replace('/[^0-9\-]/', '', $nilaiMaksimalPlafond);
            }
            
            $maksPlafondByNik[$nikKey] = (int) $nilaiMaksimalPlafond;
        }

        $maxAmount = self::toNumber($selectedPKS['Maksimal'] ?? 0);
        $terjaminNames = array_column($listTerjamin, 'NamaTerjamin');
        $invalid = [];

        foreach ($dataDebitur as $i => &$rowDebitur) {
            $debitur = $rowDebitur['debitur_kur'];
            unset($debitur['__raw']);
            $debiturName = $debitur['debitur_name'];
            // $nik = $debitur['nik'];
            $nik = $debitur['nomor_identitas_1'];

            if (!in_array($debiturName, $terjaminNames, true) || !$nik || !isset($nikTerjaminSet[(string) $nik])) {
                $invalid[] = [
                    'debitur_name' => $debiturName,
                    // 'nik' => $nik,
                    'nomor_identitas_1' => $nik,
                    // 'plafond_pembiayaan_rp' => $debitur['plafond_pembiayaan_rp'],
                    // 'plafond_kredit' => $debitur['plafond_kredit'],
                    // 'plafond_max_pembiayaan' => $debitur['plafond_max_pembiayaan'],
                    // 'nilai_kafalah' => $debitur['nilai_kafalah'],
                    // 'jenis_penjaminan' => $debitur['jenis_penjaminan'],
                    // 'status_debitur' => $debitur['status_debitur'],
                    'reason' => 'NIK and name does not registered on PKS'
                ];
                continue;
            }
            $tgl = data_get($rowDebitur, 'debitur_kur.tanggal_jatuh_tempo');

            $jatuhTempo = Carbon::createFromFormat('Y-m-d', (string) $tgl)->startOfDay();
            if (now()->startOfDay()->greaterThan($jatuhTempo)) {
                $invalid[] = [
                    'debitur_name' => $debiturName,
                    'nik' => $nik,
                    'plafond_kredit' => $debitur['plafond_kredit'] ?? null,
                    'nilai_penjaminan' => $debitur['nilai_penjaminan'] ?? null,
                    'reason' => 'Tanggal jatuh tempo harus lebih dari hari ini ',
                ];
                continue;
            }
            $tgl2 = data_get($rowDebitur, 'debitur_kur.tanggal_realisasi');
            $tglRealisasi = Carbon::createFromFormat('Y-m-d', (string) $tgl2)->startOfDay();
            if (now()->startOfDay()->greaterThan($tglRealisasi)) {
                $invalid[] = [
                    'debitur_name' => $debiturName,
                    'nik' => $nik,
                    'plafond_kredit' => $debitur['plafond_kredit'] ?? null,
                    'nilai_penjaminan' => $debitur['nilai_penjaminan'] ?? null,
                    'reason' => 'Tanggal Realisasi harus lebih dari hari ini ',
                ];
                continue;
            }
            // Continue with other validation and logic
            $maks = ($nik && isset($maksPlafondByNik[(string) $nik]))
                ? $maksPlafondByNik[(string) $nik]
                : 0;

            // Update plafond max if needed
            if (isset($dataDebitur[$i]['debitur_kur'])) {
                $dataDebitur[$i]['debitur_kur']['plafond_max_pembiayaan'] = $maks;
                if (array_key_exists('maksimal_nilai_plafond', $dataDebitur[$i]['debitur_kur'])) {
                    unset($dataDebitur[$i]['debitur_kur']['maksimal_nilai_plafond']);
                }
            } else {
                $dataDebitur[$i]['plafond_max_pembiayaan'] = $maks;
                if (array_key_exists('maksimal_nilai_plafond', $dataDebitur[$i])) {
                    unset($dataDebitur[$i]['maksimal_nilai_plafond']);
                }
            }
            $nilaiKafalah = ($debitur['plafond_kredit'] * ($riskPercentage / 100));
            $debitur['nilai_penjaminan'] = $nilaiKafalah;
            $debitur['jenis_penjaminan'] = ($debitur['plafond_kredit'] > $maxAmount) ? 'CBC' : 'CAC';
            $debitur['status_debitur'] = ($debitur['plafond_kredit'] > $maxAmount) ? 'Submitted' : 'Approved';
            $plafondPembiayaan = self::toNumber($debitur['plafond_kredit'] ?? 0);
            $plafondMax = self::toNumber($debitur['plafond_max_pembiayaan'] ?? 0);
            if ($plafondPembiayaan > $plafondMax) {
                $invalid[] = [
                    'debitur_name' => $debiturName,
                    'nik' => $nik,
                    // 'plafond_pembiayaan_rp' => $debitur['plafond_pembiayaan_rp'],
                    'plafond_kredit' => $debitur['plafond_kredit'],
                    'plafond_max_pembiayaan' => $debitur['plafond_max_pembiayaan'],
                    'nilai_penjaminan' => $nilaiKafalah,
                    'jenis_penjaminan' => $debitur['jenis_penjaminan'],
                    'status_debitur' => $debitur['status_debitur'],
                    'reason' => 'Plafond Pembiayaan RP greater than Plafond Max Pembiayaan',
                ];
            }
        }

        return [
            'success' => count($invalid) === 0,
            'message' => count($invalid) === 0
                ? 'Semua debitur valid'
                : 'Terdapat Data Debitur yang tidak sesuai',
            'list_debitur' => $invalid,
            'dataDebitur' => count($invalid) > 0 ? [] : $dataDebitur,
        ];
    }

    public static function validateItemPambayaranManual($selected_items)
    {
        if(!json_validate($selected_items) || !is_array(json_decode($selected_items)))
        {
            return [
                'success' => false,
                'message' => 'Invalid selected item data.'
            ];
        }
        $parsedItems = json_decode($selected_items);
        $arrInvoiceNoTemp = collect($parsedItems)->pluck('invoice_number')->toArray();
        if(count($arrInvoiceNoTemp) != count(array_unique($arrInvoiceNoTemp))) {
            return [
                'success' => false,
                'message' => 'Duplicate invoice data in the payload.'
            ];
        }
        return [
            'success' => true
        ];
    }

    private static function toNumber($value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value)) {
            return (int) preg_replace('/[^0-9\-]/', '', $value);
        }

        return 0;
    }
}
