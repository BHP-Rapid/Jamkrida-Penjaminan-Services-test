<?php

namespace App\Helpers;

use Carbon\Carbon;

class ValidateDebitur
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
        $invalid = [];

        foreach ($dataDebitur as $i => &$rowDebitur) {
            unset($rowDebitur['debitur_multiguna']['__raw']);

            $debitur = &$rowDebitur['debitur_multiguna'];
            $debiturName = $debitur['debitur_name'] ?? null;
            $nik = $debitur['nik'] ?? null;

            // Validasi NIK dan nama jika ingin diaktifkan kembali
            /*
            if (!$nik || !isset($nikTerjaminSet[(string) $nik])) {
                $invalid[] = [
                    'debitur_name' => $debiturName,
                    'nik' => $nik,
                    'plafond_pembiayaan_rp' => $debitur['plafond_pembiayaan_rp'] ?? null,
                    'nilai_kafalah' => $debitur['nilai_kafalah'] ?? null,
                    'reason' => 'NIK does not registered on PKS',
                ];
                continue;
            }
            */

            $tglJatuhTempo = data_get($rowDebitur, 'debitur_multiguna.tanggal_jatuh_tempo');
            if (!empty($tglJatuhTempo)) {
                try {
                    $jatuhTempo = Carbon::createFromFormat('Y-m-d', (string) $tglJatuhTempo)->startOfDay();
                    if (now()->startOfDay()->greaterThan($jatuhTempo)) {
                        $invalid[] = [
                            'debitur_name' => $debiturName,
                            'nik' => $nik,
                            'plafond_pembiayaan_rp' => $debitur['plafond_pembiayaan_rp'] ?? null,
                            'nilai_kafalah' => $debitur['nilai_kafalah'] ?? null,
                            'reason' => 'Tanggal jatuh tempo harus lebih dari hari ini',
                        ];
                        continue;
                    }
                } catch (\Exception $e) {
                    $invalid[] = [
                        'debitur_name' => $debiturName,
                        'nik' => $nik,
                        'plafond_pembiayaan_rp' => $debitur['plafond_pembiayaan_rp'] ?? null,
                        'nilai_kafalah' => $debitur['nilai_kafalah'] ?? null,
                        'reason' => 'Format tanggal jatuh tempo tidak valid',
                    ];
                    continue;
                }
            }

            $tglRealisasi = data_get($rowDebitur, 'debitur_multiguna.tanggal_realisasi');
            if (!empty($tglRealisasi)) {
                try {
                    $realisasi = Carbon::createFromFormat('Y-m-d', (string) $tglRealisasi)->startOfDay();
                    if (now()->startOfDay()->greaterThan($realisasi)) {
                        $invalid[] = [
                            'debitur_name' => $debiturName,
                            'nik' => $nik,
                            'plafond_pembiayaan_rp' => $debitur['plafond_pembiayaan_rp'] ?? null,
                            'nilai_kafalah' => $debitur['nilai_kafalah'] ?? null,
                            'reason' => 'Tanggal realisasi harus lebih dari hari ini',
                        ];
                        continue;
                    }
                } catch (\Exception $e) {
                    $invalid[] = [
                        'debitur_name' => $debiturName,
                        'nik' => $nik,
                        'plafond_pembiayaan_rp' => $debitur['plafond_pembiayaan_rp'] ?? null,
                        'nilai_kafalah' => $debitur['nilai_kafalah'] ?? null,
                        'reason' => 'Format tanggal realisasi tidak valid',
                    ];
                    continue;
                }
            }

            $maks = ($nik && isset($maksPlafondByNik[(string) $nik]))
                ? $maksPlafondByNik[(string) $nik]
                : 0;

            $debitur['plafond_max_pembiayaan'] = $maks;

            if (array_key_exists('maksimal_nilai_plafond', $debitur)) {
                unset($debitur['maksimal_nilai_plafond']);
            }

            $plafondPembiayaan = self::toNumber($debitur['plafond_pembiayaan_rp'] ?? 0);
            $nilaiKafalah = $plafondPembiayaan * ($riskPercentage / 100);

            $debitur['nilai_kafalah'] = $nilaiKafalah;
            $debitur['jenis_penjaminan'] = $plafondPembiayaan > $maxAmount ? 'CBC' : 'CAC';
            $debitur['status_debitur'] = $plafondPembiayaan > $maxAmount ? 'Submitted' : 'Approved';

            $plafondMax = self::toNumber($debitur['plafond_max_pembiayaan'] ?? 0);

            // Validasi plafond jika ingin diaktifkan kembali
            /*
            if ($plafondPembiayaan > $plafondMax) {
                $invalid[] = [
                    'debitur_name' => $debiturName,
                    'nik' => $nik,
                    'plafond_pembiayaan_rp' => $debitur['plafond_pembiayaan_rp'],
                    'plafond_max_pembiayaan' => $debitur['plafond_max_pembiayaan'],
                    'nilai_kafalah' => $nilaiKafalah,
                    'jenis_penjaminan' => $debitur['jenis_penjaminan'],
                    'status_debitur' => $debitur['status_debitur'],
                    'reason' => 'Plafond Pembiayaan RP greater than Plafond Max Pembiayaan',
                ];
            }
            */
        }

        unset($rowDebitur);

        return [
            'success' => count($invalid) === 0,
            'message' => count($invalid) === 0
                ? 'Semua debitur valid'
                : 'Terdapat Data Debitur yang tidak sesuai',
            'list_debitur' => $invalid,
            'dataDebitur' => count($invalid) > 0 ? [] : $dataDebitur,
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
