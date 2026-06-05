<?php

namespace App\Services;

use App\Helpers\RabbitMQHelper;
use App\Models\Institution;
use App\Models\MultigunaDebitur;
use App\Models\PenjaminanTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class PenjaminanMultigunaRabbitPublisher
{
    private const REGISTRASI_ENDPOINT = 'PermohonanPenjaminan/RegistrasiMultiguna';

    private const REGISTRASI_PATH = '/0/rest/PermohonanPenjaminan/RegistrasiMultiguna';

    public function __construct(
        private AuthInternalClient $authInternalClient,
    ) {
    }

    public function dispatchRegistration(string $trxNo, string $bulkNo, string $userToken): void
    {
        $penjaminan = PenjaminanTransaction::query()
            ->join('multiguna_transaction as mt', 'transaction_penjaminan_header.trx_no', '=', 'mt.trx_no')
            ->where('transaction_penjaminan_header.trx_no', $trxNo)
            ->select('transaction_penjaminan_header.*', 'mt.*')
            ->first();

        if (! $penjaminan) {
            throw new RuntimeException("Penjaminan {$trxNo} not found for RabbitMQ dispatch.");
        }

        if ((int) ($penjaminan->status_sync_creatio ?? 0) === 1) {
            Log::info('Skipping RabbitMQ dispatch because penjaminan is already marked as synced.', [
                'trx_no' => $trxNo,
                'bulk_no' => $bulkNo,
            ]);

            return;
        }

        $correlationId = (string) Str::uuid();
        $sentAt = now()->toISOString();
        $payload = $this->buildRegistrationPayload($penjaminan, $userToken);

        $message = [
            'type' => 'in',
            'version' => 1,
            'occurred_at' => $sentAt,
            'correlation_id' => $correlationId,
            'data' => [
                'endpoint' => self::REGISTRASI_ENDPOINT,
                'method' => 'POST',
                'path' => self::REGISTRASI_PATH,
                'payload' => $payload,
            ],
        ];

        $queue = (string) config('services.rabbitmq.queue', 'integration.in');
        $routingKey = (string) config('services.rabbitmq.routing_key', 'in');

        $ok = RabbitMQHelper::safeDispatch($message, $queue, [
            'type' => $message['type'],
            'routing_key' => $routingKey,
            'correlation_id' => $correlationId,
            'message_id' => 'penjaminan:'.$trxNo.':registrasi-multiguna',
            'app_id' => 'jamkrida-penjaminan-service',
            'headers' => [
                'endpoint' => self::REGISTRASI_ENDPOINT,
                'penjaminan_trx_no' => $trxNo,
                'bulk_no' => $bulkNo,
                'product' => 'mlt',
            ],
        ]);

        if (! $ok) {
            throw new RuntimeException('Failed to dispatch penjaminan multiguna registration to RabbitMQ.');
        }

        PenjaminanTransaction::query()
            ->where('trx_no', $trxNo)
            ->update([
                'status_sync_creatio' => 1,
                'updated_at' => Carbon::now('Asia/Jakarta'),
            ]);

        Log::info('Penjaminan multiguna registration dispatched to RabbitMQ.', [
            'trx_no' => $trxNo,
            'bulk_no' => $bulkNo,
            'correlation_id' => $correlationId,
            'queue' => $queue,
            'routing_key' => $routingKey,
        ]);
    }

    public function buildRegistrationPayload(object $penjaminan, string $userToken): array
    {
        $debiturs = MultigunaDebitur::query()
            ->where('multiguna_trx_id', $penjaminan->id_multiguna)
            ->get();

        $institutionIds = $debiturs->pluck('institution_id')->filter()->unique()->values();
        $institutionsById = Institution::query()
            ->whereIn('institution_id', $institutionIds)
            ->get()
            ->keyBy('institution_id');

        $tenantMitra = $this->getTenantMitraFromAuthMaster((string) $penjaminan->mitra_id, $userToken);

        $nowJakarta = Carbon::now('Asia/Jakarta');

        return [
            'PermohonanPenjaminanMultiguna' => [
                [
                    'CaraBayar' => $penjaminan->sp_split == true ? 'Installment' : 'Full Payment',
                    'PKS' => $penjaminan->pks_number,
                    'TanggalSuratPermohonan' => $this->dateString($penjaminan->tanggal_surat_permohonan),
                    'NomorSuratPermohonan' => $penjaminan->no_surat_permohonan,
                    'MitraId' => $penjaminan->mitra_id,
                    'TarifPercentage' => $this->numericValue($penjaminan->fee_base_number),
                    'NomorPermohonan' => $penjaminan->no_surat_permohonan,
                    'BankCabang' => trim(($penjaminan->bank_code ?? '').' - '.($penjaminan->bank_name ?? ''), ' -'),
                    'FeeBasePercentage' => $this->numericValue($penjaminan->fee_base_percentage),
                    'TeksPercentagePenjaminandiSP' => $this->numericValue($penjaminan->text_certified),
                    'IsConven' => (bool) ($tenantMitra['is_conventional'] ?? false),
                    'ListDebitur' => $debiturs
                        ->map(function (MultigunaDebitur $debitur) use ($institutionsById, $nowJakarta): array {
                            $institution = $institutionsById->get($debitur->institution_id);

                            return [
                                'Name' => $debitur->debitur_name,
                                'Nik' => $debitur->nik,
                                'NamaMakhfulAnhu' => $debitur->jenis_makful_anhu,
                                'TanggalLahir' => $this->dateString($institution?->birth_date),
                                'NilaiKafalah' => $this->numericValue($debitur->nilai_kafalah),
                                'TanggalRealisasi' => $this->dateString($debitur->tanggal_realisasi),
                                'NilaiAgunan' => $this->numericValue($debitur->nilai_agunan),
                                'JenisAgunan' => $debitur->jenis_agunan,
                                'JenisMakhfulAnhu' => $debitur->jenis_makful_anhu,
                                'InstansiPekerjaanTerjamin' => $institution?->job_employer_name,
                                'JwBulan' => (int) ($debitur->jw_bulan ?? 0),
                                'JenisKelamin' => $this->genderLabel($institution?->gender),
                                'MarginBagiHasilUjrahTahun' => $this->numericValue($debitur->margin),
                                'JumlahDana' => $this->numericValue($debitur->plafond_pembiayaan),
                                'PlafonPembiayaan' => $this->numericValue($debitur->plafond_pembiayaan),
                                'LoanNumber' => $debitur->loan_number,
                                'TanggalJatuhTempo' => $this->dateString($debitur->tanggal_jatuh_tempo),
                                'PenggunaanPembiayaan' => $debitur->penggunaan_pembiayaan,
                                'TenagaKerja' => $debitur->tenaga_kerja,
                                'Tanggal' => $nowJakarta->toDateString(),
                                'Tenor' => (int) ($debitur->jw_bulan ?? 0),
                            ];
                        })
                        ->values()
                        ->all(),
                ],
            ],
        ];
    }

    private function getTenantMitraFromAuthMaster(string $mitraId, string $userToken): array
    {
        if (trim($userToken) === '') {
            throw new RuntimeException('User token is required to get tenant mitra from Auth Master.');
        }

        $response = $this->authInternalClient->getTenantMitra($mitraId, $userToken);
        $data = $response['data'] ?? null;

        if (! is_array($data)) {
            throw new RuntimeException('Invalid tenant mitra response from Auth Master.');
        }

        return $data;
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return Carbon::parse($value)->format('Y-m-d');
    }

    private function genderLabel(mixed $gender): ?string
    {
        return match (strtoupper(trim((string) $gender))) {
            'L', 'LAKI-LAKI' => 'Laki-Laki',
            'P', 'PEREMPUAN' => 'Perempuan',
            default => null,
        };
    }

    private function numericValue(mixed $value): int|float|null
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (is_numeric($value)) {
            return $value + 0;
        }

        $normalized = preg_replace('/[^0-9,.-]/', '', (string) $value) ?? '';
        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            if (strrpos($normalized, ',') > strrpos($normalized, '.')) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        return is_numeric($normalized) ? $normalized + 0 : null;
    }
}
