<?php

namespace App\Services\Invoice;

use App\Helpers\AesHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ProcessRabbitMqInvoiceService
{
    private ?bool $customBondHasExternalInvoiceId = null;

    /**
     * Supports Creatio grouped invoice payloads:
     * - payload[].Produk/CaraBayar/Data[]
     * - Data[].Produk/CaraBayar/Data[]
     *
     * @return array{inserted: int, updated: int, duplicates: int, unmatched: int, skipped: int, activated: int}
     */
    public function process(array $payload): array
    {
        $groups = $payload['payload'] ?? $payload['Data'] ?? null;

        if (! is_array($groups)) {
            throw new InvalidArgumentException('Invoice payload must contain a payload or Data array.');
        }

        return DB::transaction(function () use ($groups): array {
            $result = [
                'inserted' => 0,
                'updated' => 0,
                'duplicates' => 0,
                'unmatched' => 0,
                'skipped' => 0,
                'activated' => 0,
            ];

            foreach ($groups as $group) {
                $this->processGroup($group, $result);
            }

            return $result;
        });
    }

    /**
     * @param array{inserted: int, updated: int, duplicates: int, unmatched: int, skipped: int, activated: int} $result
     */
    private function processGroup(mixed $group, array &$result): void
    {
        if (! is_array($group)) {
            $result['skipped']++;
            Log::warning('RabbitMQ invoice group skipped because it is not an object.');
            return;
        }

        $product = $this->productCode($this->requiredText($group, 'Produk', 'Invoice group Produk'));
        $payment = $this->paymentCode($this->requiredText($group, 'CaraBayar', 'Invoice group CaraBayar'));

        if (! is_array($group['Data'] ?? null)) {
            throw new InvalidArgumentException('Invoice group Data must be an array.');
        }

        if (isset($group['Count']) && (int) $group['Count'] !== count($group['Data'])) {
            throw new InvalidArgumentException('Invoice group Count does not match the Data item count.');
        }

        if (! in_array($payment, ['full', 'installment'], true)) {
            throw new InvalidArgumentException("Unsupported payment method [{$group['CaraBayar']}].");
        }

        foreach ($group['Data'] as $invoice) {
            if (! is_array($invoice)) {
                throw new InvalidArgumentException('Each invoice item must be an object.');
            }

            match ($product) {
                'srtb' => $this->processSuretyBond($invoice, $result, $payment, $group),
                'cstb' => $this->processCustomBond($invoice, $result, $payment, $group),
                'mlt' => $this->processMultiguna($invoice, $result, $payment, $group),
                default => throw new InvalidArgumentException("Unsupported invoice product [{$group['Produk']}]."),
            };
        }
    }

    /**
     * @param array{inserted: int, updated: int, duplicates: int, unmatched: int, skipped: int, activated: int} $result
     */
    private function processSuretyBond(array $invoice, array &$result, string $payment, array $group): void
    {
        $externalInvoiceId = $this->text($invoice, ['Id', 'id']);
        $invoiceNumber = $this->requiredText($invoice, 'Name', 'Invoice Name');
        $noPermohonan = $this->requiredText($invoice, 'NomorPermohonan', 'Invoice NomorPermohonan');
        $amount = $this->amount($invoice, 'TotalTagihan', $invoiceNumber);
        $isCollateral = $this->isCollateralInvoice($invoice);
        $tenorSequence = $this->tenorSequence($invoice, $payment, $invoiceNumber, $group);

        if ($externalInvoiceId !== null && ! Str::isUuid($externalInvoiceId)) {
            throw new InvalidArgumentException("Invoice [{$invoiceNumber}] Id must be a valid UUID.");
        }

        $transaction = DB::table('transaction_penjaminan_header as tph')
            ->join('surety_bond_transaction as sbt', 'sbt.trx_no', '=', 'tph.trx_no')
            ->where('tph.no_surat_permohonan', $noPermohonan)
            ->select('sbt.id_trx_product')
            ->first();

        if (! $transaction) {
            $result['unmatched']++;
            Log::warning('RabbitMQ Surety Bond invoice skipped because permohonan was not found.', [
                'external_invoice_id' => $externalInvoiceId,
                'invoice_number' => $invoiceNumber,
                'no_permohonan' => $noPermohonan,
            ]);
            return;
        }

        $now = Carbon::now('Asia/Jakarta');
        $tenor = DB::table('suretybond_tenor_schedule')
            ->where('id_trx_product', $transaction->id_trx_product)
            ->where(function ($query) use ($tenorSequence): void {
                $query->where('tenor_sequence', $tenorSequence);

                if ($tenorSequence === 0) {
                    $query->orWhereNull('tenor_sequence');
                }
            })
            ->first();

        if (! $tenor) {
            $insertData = [
                'id_trx_product' => $transaction->id_trx_product,
                'tenor_sequence' => $tenorSequence,
                'due_date' => $this->dateValue($invoice['TanggalJatuhTempo'] ?? null, $invoiceNumber),
                'status' => 'Pending',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($isCollateral) {
                $insertData += [
                    'status_collateral' => 'Pending',
                    'invoice_number_collateral' => $invoiceNumber,
                    'collateral_amount' => $amount,
                ];
            } else {
                $insertData += [
                    'invoice_number' => $invoiceNumber,
                    'amount' => $amount,
                    'status_collateral' => null,
                    'invoice_number_collateral' => null,
                    'collateral_amount' => null,
                ];
            }

            DB::table('suretybond_tenor_schedule')->insert($insertData);
            $result['inserted']++;
            return;
        }

        $updateData = [
            'due_date' => $this->dateValue($invoice['TanggalJatuhTempo'] ?? null, $invoiceNumber),
            'updated_at' => $now,
        ];

        if ($isCollateral) {
            $updateData += [
                'status_collateral' => 'Pending',
                'invoice_number_collateral' => $invoiceNumber,
                'collateral_amount' => $amount,
            ];
        } else {
            $updateData += [
                'status' => 'Pending',
                'invoice_number' => $invoiceNumber,
                'tenor_sequence' => $tenorSequence,
                'amount' => $amount,
            ];
        }

        DB::table('suretybond_tenor_schedule')
            ->where('srtb_schedule_id', $tenor->srtb_schedule_id)
            ->update($updateData);

        $result['updated']++;
    }
    /**
     * @param array{inserted: int, updated: int, duplicates: int, unmatched: int, skipped: int, activated: int} $result
     */
    private function processCustomBond(array $invoice, array &$result, string $payment, array $group): void
    {
        $externalInvoiceId = $this->requiredText($invoice, 'Id', 'Invoice Id');
        $invoiceNumber = $this->requiredText($invoice, 'Name', 'Invoice Name');
        $noPermohonan = $this->requiredText($invoice, 'NomorPermohonan', 'Invoice NomorPermohonan');
        $amount = $this->amount($invoice, 'TotalTagihan', $invoiceNumber);
        $tenorSequence = $this->tenorSequence($invoice, $payment, $invoiceNumber, $group);

        if (! Str::isUuid($externalInvoiceId)) {
            throw new InvalidArgumentException("Invoice [{$invoiceNumber}] Id must be a valid UUID.");
        }

        if (mb_strlen($invoiceNumber) > 10) {
            throw new InvalidArgumentException("Invoice number [{$invoiceNumber}] exceeds the current 10-character database limit.");
        }

        $transaction = DB::table('transaction_penjaminan_header as tph')
            ->join('custom_bond_transaction as cbt', 'cbt.trx_no', '=', 'tph.trx_no')
            ->where('tph.no_surat_permohonan', $noPermohonan)
            ->select('cbt.id_bond')
            ->first();

        if (! $transaction) {
            $result['unmatched']++;
            Log::warning('RabbitMQ Custom Bond invoice skipped because permohonan was not found.', [
                'external_invoice_id' => $externalInvoiceId,
                'invoice_number' => $invoiceNumber,
                'no_permohonan' => $noPermohonan,
            ]);
            return;
        }

        if ($this->customBondScheduleExists($transaction->id_bond, $invoiceNumber, $externalInvoiceId)) {
            $result['duplicates']++;
            return;
        }

        $this->insertCustomBondSchedule([
            'external_invoice_id' => $externalInvoiceId,
            'id_bond' => $transaction->id_bond,
            'tenor_sequence' => $tenorSequence,
            'due_date' => $this->dateValue($invoice['TanggalJatuhTempo'] ?? null, $invoiceNumber),
            'invoice_number' => $invoiceNumber,
            'amount' => $amount,
            'status' => 'Pending',
        ]);

        $result['inserted']++;
    }

    /**
     * @param array{inserted: int, updated: int, duplicates: int, unmatched: int, skipped: int, activated: int} $result
     */
    private function processMultiguna(array $invoice, array &$result, string $payment, array $group): void
    {
        $externalInvoiceId = $this->text($invoice, ['Id', 'id']);
        $invoiceNumber = $this->requiredText($invoice, 'Name', 'Invoice Name');
        $noPermohonan = $this->requiredText($invoice, 'NomorPermohonan', 'Invoice NomorPermohonan');
        $amount = $this->amount($invoice, 'TotalTagihan', $invoiceNumber);
        $tenorSequence = $this->tenorSequence($invoice, $payment, $invoiceNumber, $group);
        $payloadNik = is_array($invoice['Nasabah'] ?? null)
            ? $this->text($invoice['Nasabah'], ['NIK', 'nik', 'Nik'])
            : null;

        if ($externalInvoiceId !== null && ! Str::isUuid($externalInvoiceId)) {
            throw new InvalidArgumentException("Invoice [{$invoiceNumber}] Id must be a valid UUID.");
        }

        if ($payloadNik === null) {
            $result['skipped']++;
            Log::warning('RabbitMQ Multiguna invoice skipped because Nasabah.NIK is missing.', [
                'external_invoice_id' => $externalInvoiceId,
                'invoice_number' => $invoiceNumber,
                'no_permohonan' => $noPermohonan,
            ]);
            return;
        }

        $rows = DB::table('transaction_penjaminan_header as tph')
            ->join('multiguna_transaction as mt', 'mt.trx_no', '=', 'tph.trx_no')
            ->join('multiguna_debitur as md', 'md.multiguna_trx_id', '=', 'mt.id_multiguna')
            ->where(function ($query) use ($noPermohonan): void {
                $query->where('tph.no_surat_permohonan', $noPermohonan)
                    ->orWhere('md.no_sp_detail', $noPermohonan);
            })
            ->select('md.id_trx_debitur', 'md.nik')
            ->get();

        if ($rows->isEmpty()) {
            $result['unmatched']++;
            Log::warning('RabbitMQ Multiguna invoice skipped because permohonan or no_sp_detail was not found.', [
                'external_invoice_id' => $externalInvoiceId,
                'invoice_number' => $invoiceNumber,
                'no_permohonan' => $noPermohonan,
                'nik' => $payloadNik,
            ]);
            return;
        }

        $rowsByNik = $this->rowsByDecryptedNik($rows, [$payloadNik]);
        if (! array_key_exists($payloadNik, $rowsByNik)) {
            $result['unmatched']++;
            Log::warning('RabbitMQ Multiguna invoice skipped because Nasabah.NIK did not match any debitur.', [
                'external_invoice_id' => $externalInvoiceId,
                'invoice_number' => $invoiceNumber,
                'no_permohonan' => $noPermohonan,
                'nik' => $payloadNik,
            ]);
            return;
        }

        $now = Carbon::now('Asia/Jakarta');
        foreach ($rowsByNik[$payloadNik] as $row) {
            $exists = DB::table('multiguna_tenor_schedule')
                ->where('invoice_number', $invoiceNumber)
                ->exists();

            if ($exists) {
                $result['duplicates']++;
            } else {
                DB::table('multiguna_tenor_schedule')->insert([
                    'id_trx_debitur' => $row->id_trx_debitur,
                    'invoice_number' => $invoiceNumber,
                    'due_date' => $this->dateValue($invoice['TanggalJatuhTempo'] ?? null, $invoiceNumber),
                    'invoice_id' => null,
                    'tenor_sequence' => $tenorSequence,
                    'status' => 'Pending',
                    'amount' => $amount,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $result['inserted']++;
            }

            DB::table('multiguna_debitur')
                ->where('id_trx_debitur', $row->id_trx_debitur)
                ->update(['updated_at' => $now, 'is_active' => true]);

            $result['activated']++;
        }
    }

    /**
     * @param iterable<object> $rows
     * @return array<string, array<int, object>>
     */
    private function rowsByDecryptedNik(iterable $rows, array $payloadNiks): array
    {
        $key = base64_decode((string) config('services.secure.key'));
        $rowsByNik = [];

        foreach ($rows as $row) {
            $decryptedNik = AesHelper::decrypt($row->nik, $key);
            if (! in_array($decryptedNik, $payloadNiks, true)) {
                continue;
            }

            $rowsByNik[$decryptedNik] ??= [];
            $rowsByNik[$decryptedNik][] = $row;
        }

        return $rowsByNik;
    }

    private function customBondScheduleExists(int|string $idBond, string $invoiceNumber, ?string $externalInvoiceId): bool
    {
        return DB::table('custombond_tenor_schedule')
            ->where(function ($query) use ($externalInvoiceId, $idBond, $invoiceNumber): void {
                $query->where(function ($query) use ($idBond, $invoiceNumber): void {
                    $query->where('id_bond', $idBond)
                        ->where('invoice_number', $invoiceNumber);
                });

                if ($externalInvoiceId !== null && $this->hasCustomBondExternalInvoiceId()) {
                    $query->orWhere('external_invoice_id', $externalInvoiceId);
                }
            })
            ->exists();
    }

    private function insertCustomBondSchedule(array $data): void
    {
        $now = Carbon::now('Asia/Jakarta');
        $insertData = [
            'id_bond' => $data['id_bond'],
            'tenor_sequence' => $data['tenor_sequence'],
            'due_date' => $data['due_date'],
            'invoice_number' => $data['invoice_number'],
            'amount' => $data['amount'],
            'status' => $data['status'],
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($this->hasCustomBondExternalInvoiceId()) {
            $insertData['external_invoice_id'] = $data['external_invoice_id'] ?? null;
        }

        DB::table('custombond_tenor_schedule')->insert($insertData);
    }

    private function hasCustomBondExternalInvoiceId(): bool
    {
        return $this->customBondHasExternalInvoiceId ??= Schema::hasColumn(
            'custombond_tenor_schedule',
            'external_invoice_id'
        );
    }

    private function requiredText(array $data, string $field, string $label): string
    {
        $value = $this->text($data, $field);

        if ($value === null) {
            throw new InvalidArgumentException("{$label} is required.");
        }

        return $value;
    }

    private function text(array $data, array|string $fields): ?string
    {
        foreach ((array) $fields as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];
            if (is_string($value) || is_numeric($value)) {
                $value = trim((string) $value);
                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    private function amount(array $data, array|string $fields, string $invoiceNumber): float
    {
        foreach ((array) $fields as $field) {
            if (! array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                continue;
            }

            if (! is_numeric($data[$field])) {
                throw new InvalidArgumentException("Invoice [{$invoiceNumber}] field [{$field}] must be numeric.");
            }

            $amount = (float) $data[$field];
            if ($amount < 0) {
                throw new InvalidArgumentException("Invoice [{$invoiceNumber}] field [{$field}] cannot be negative.");
            }

            return $amount;
        }

        throw new InvalidArgumentException("Invoice [{$invoiceNumber}] amount is required.");
    }

    private function dateValue(mixed $value, string $invoiceNumber): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("Invoice [{$invoiceNumber}] date must be a string or null.");
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            throw new InvalidArgumentException("Invoice [{$invoiceNumber}] has an invalid date.");
        }
    }

    private function isCollateralInvoice(array $invoice): bool
    {
        foreach (['is_collateral', 'isCollateral', 'IsCollateral'] as $field) {
            if (array_key_exists($field, $invoice)) {
                return $this->boolValue($invoice[$field]);
            }
        }

        foreach (['TypeInvoice', 'JenisInvoice', 'InvoiceType', 'JenisTagihan'] as $field) {
            if (! is_array($invoice[$field] ?? null)) {
                continue;
            }

            $name = $this->text($invoice[$field], ['Name', 'name']);
            if ($name !== null && str_contains($this->normalizeLabel($name), 'collateral')) {
                return true;
            }

            if ($name !== null && str_contains($this->normalizeLabel($name), 'agunan')) {
                return true;
            }
        }

        return false;
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (bool) $value;
        }

        if (is_string($value)) {
            return in_array(mb_strtolower(trim($value)), ['1', 'true', 'yes', 'y'], true);
        }

        return false;
    }
    private function productCode(string $product): string
    {
        return match ($this->normalizeLabel($product)) {
            'surety bond', 'suretybond', 'srtb' => 'srtb',
            'custom bond', 'custombond', 'cstb' => 'cstb',
            'multiguna', 'multi guna', 'mlt' => 'mlt',
            default => '',
        };
    }

    private function tenorSequence(array $invoice, string $payment, string $invoiceNumber, array $group): int
    {
        if ($payment === 'full') {
            return 0;
        }

        $value = $this->sequenceValue($invoice)
            ?? $this->sequenceValue($invoice['Nasabah'] ?? [])
            ?? $this->sequenceValue($invoice['Debitur'] ?? [])
            ?? $this->sequenceValue($group);

        if ($value === null) {
            throw new InvalidArgumentException("Invoice [{$invoiceNumber}] tenor_sequence is required for Installment payment.");
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Invoice [{$invoiceNumber}] tenor_sequence must be numeric.");
        }

        $number = (float) $value;
        $sequence = (int) $number;

        if ($sequence < 1 || $number !== (float) $sequence) {
            throw new InvalidArgumentException("Invoice [{$invoiceNumber}] tenor_sequence must be a positive integer.");
        }

        return $sequence;
    }

    private function sequenceValue(mixed $data): int|float|string|null
    {
        if (! is_array($data)) {
            return null;
        }

        foreach ([
            'TenorSequence',
            'tenor_sequence',
            'Tenor',
            'tenor',
            'TenorKe',
            'tenor_ke',
            'TenorId',
            'tenorId',
            'AngsuranKe',
            'angsuran_ke',
            'InstallmentSequence',
            'installment_sequence',
            'InstallmentNo',
            'installment_no',
            'Sequence',
            'sequence',
        ] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];
            if (is_string($value) || is_numeric($value)) {
                return $value;
            }
        }

        return null;
    }

    private function paymentCode(string $payment): string
    {
        return match ($this->normalizeLabel($payment)) {
            'full', 'full payment', 'fullpayment' => 'full',
            'installment', 'installment payment', 'installmentpayment', 'split', 'split payment', 'splitpayment', 'angsuran' => 'installment',
            default => '',
        };
    }

    private function normalizeLabel(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }
}
