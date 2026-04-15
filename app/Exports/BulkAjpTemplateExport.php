<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class BulkAjpTemplateExport implements FromArray, WithHeadings, WithEvents, ShouldAutoSize
{
    public function __construct(
        private int $prefillRows = 500
    ) {}

    public function headings(): array
    {
        return [
            'no_urut',
            'nama_nasabah',
            'phone_1',
            'email_1',
            'home_province',
            'home_city',
            'home_district',
            'home_sub_district',
            'home_zipcode',
            'alamat_nasabah',
            'birth_date',
            'birth_place',
            'gender',
            'id_type',
            'id_number',
            'npwp',
            'job_id',
            'job_level',
            'job_employer_name',
            'job_start_date',
            'job_industry_type',
            'current_salary_amount',
            'current_salary_currency',
            'no_invoice',
            'tanggal_invoice',
            'tanggal_jatuh_tempo_invoice',
            'nilai_invoice',
            'nama_payor',
            'jenis_payor',
            'no_perjanjian_pembayaran',
            'tanggal_perjanjian_pembayaran',
            'penggunaan_kredit',
            'plafond_kredit',
            'nilai_penjaminan',
            'JW Bulan',
            'tanggal_realisasi',
            'tanggal_jatuh_tempo',
            'jenis_agunan',
            'nilai_agunan',
            'tenaga_kerja',
            'jenis_terjamin',
            'ijp',
            'loan_number',
            'catatan',
            'jenis_penjaminan',
            'status_debitur',
            'no_sp_core_debitur',
        ];
    }

    public function array(): array
    {
        return [
            [
                1,
                'Budi Santoso',
                '081234567890',
                'budi@example.com',
                'DKI Jakarta',
                'Jakarta Selatan',
                'Kebayoran Baru',
                'Senayan',
                '12190',
                'Jl. Sudirman No. 1, Jakarta',
                '1990-01-15',
                'Jakarta',
                'L',
                'KTP',
                '3171234567890001',
                '12.345.678.9-000.000',
                'EMPLOYEE',
                'STAFF',
                'PT Maju Jaya',
                '2020-01-01',
                'PERDAGANGAN',
                8500000,
                'IDR',
                'INV-AJP-0001',
                '2026-03-30',
                '2026-04-30',
                100000000,
                'PT Payor Utama',
                'Korporasi',
                'PP-001/AJP/2026',
                '2026-03-30',
                'Modal Kerja',
                80000000,
                60000000,
                12,
                '2026-04-01',
                '2027-04-01',
                'Piutang',
                50000000,
                '10',
                '1',
                2500000,
                'MDR20260001',
                'Sample data AJP',
                'CAC',
                'Approved',
                'CORE-SP-0001',
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $headings = $this->headings();
                $colCount = count($headings);
                $lastColLetter = Coordinate::stringFromColumnIndex($colCount);

                $sheet->freezePane('A2');
                $sheet->setAutoFilter("A1:{$lastColLetter}1");
                $sheet->getStyle("A1:{$lastColLetter}1")->getFont()->setBold(true);

                $colIndexByName = [];
                foreach ($headings as $idx => $name) {
                    $colIndexByName[$name] = $idx + 1;
                }
                $col = fn(string $name) => Coordinate::stringFromColumnIndex($colIndexByName[$name]);

                $maxRow = $this->prefillRows + 1;

                foreach (['id_number', 'npwp', 'phone_1', 'home_zipcode', 'loan_number', 'no_invoice', 'no_sp_core_debitur'] as $h) {
                    $range = $col($h) . "2:" . $col($h) . $maxRow;
                    $sheet->getStyle($range)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
                }

                foreach (['birth_date', 'job_start_date', 'tanggal_invoice', 'tanggal_jatuh_tempo_invoice', 'tanggal_perjanjian_pembayaran', 'tanggal_realisasi', 'tanggal_jatuh_tempo'] as $h) {
                    $range = $col($h) . "2:" . $col($h) . $maxRow;
                    $sheet->getStyle($range)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
                }

                foreach (['current_salary_amount', 'nilai_invoice', 'plafond_kredit', 'nilai_penjaminan', 'nilai_agunan', 'ijp'] as $h) {
                    $range = $col($h) . "2:" . $col($h) . $maxRow;
                    $sheet->getStyle($range)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
                }

                $this->addDropdown($sheet, $col('gender') . "2:" . $col('gender') . $maxRow, ['L', 'P']);
                $this->addDropdown($sheet, $col('current_salary_currency') . "2:" . $col('current_salary_currency') . $maxRow, ['IDR', 'USD']);

                $sheet->getStyle("A1:{$lastColLetter}{$maxRow}")
                    ->getProtection()->setLocked(false);

                $sheet->getStyle("A1:{$lastColLetter}1")
                    ->getProtection()->setLocked(true);

                $protection = $sheet->getProtection();
                $protection->setSheet(true);
            },
        ];
    }

    private function addDropdown($sheet, string $range, array $options): void
    {
        $validation = new DataValidation();
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setAllowBlank(true);
        $validation->setShowDropDown(true);
        $validation->setShowErrorMessage(true);
        $validation->setErrorTitle('Nilai tidak valid');
        $validation->setError('Pilih nilai dari dropdown.');
        $validation->setFormula1('"' . implode(',', $options) . '"');

        [$start, $end] = explode(':', $range);
        $startCol = preg_replace('/\d+/', '', $start);
        $startRow = (int) preg_replace('/\D+/', '', $start);
        $endRow = (int) preg_replace('/\D+/', '', $end);

        for ($r = $startRow; $r <= $endRow; $r++) {
            $sheet->getCell("{$startCol}{$r}")->setDataValidation(clone $validation);
        }
    }
}
