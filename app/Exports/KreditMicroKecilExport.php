<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\Exportable;


class KreditMikroKecilExport implements FromCollection, WithHeadings, WithEvents, ShouldAutoSize
{
    use Exportable;

    public function collection()
    {
        return collect([
            [
                'Ahmad Sulaiman',
                '+62-812-3456-7890',
                'ahmad.sulaiman@example.com',
                'Jawa Barat',
                'Bandung',
                'Cicendo',
                'Pasir Kaliki',
                '40171',
                '1985-03-12',
                'Bandung',
                'L',
                'Siti Aminah',
                'KTP',
                '3275011203850001',
                '01.234.567.8-901.000',
                '',
                'Wiraswasta',
                'Pemilik',
                'CV Sinar Jaya',
                '2010-06-01',
                'IND-045',
                6000000,
                'IDR',
                'Modal kerja dan pembelian mesin',
                50000000,
                'Kredit 1',
                5,
                12,
                '2025-11-01',
                '2028-11-01',
                1,
                60000000,
                3,
                2,
                '',
                ''
            ]
        ]);
    }

    public function headings(): array
    {
        return [
            'Nama Nasabah',
            'Nomor Telepon',
            'Email',
            'Provinsi',
            'Kota/Kabupaten',
            'Kecamatan',
            'Kelurahan',
            'Kode Pos',
            'Tanggal Lahir',
            'Tempat Lahir',
            'Jenis Kelamin',
            'Nama Ibu Kandungan',
            'Jenis Identitas',
            'Nomor Identitas 1',
            'NPWP (Giro)',
            'Nomor Identitas 2',
            'Kategori Pekerjaan',
            'Jabatan',
            'Nama Pemberi Kerja',
            'Tanggal Mulai Bekerja',
            'Kode Industri Internal Pemberi Kerja',
            'Gaji Sekarang',
            'Kode Valuta IDR/USD',
            'Penggunaan Kredit',
            'Plafond Kredit',
            'Jenis Kredit',
            'Suku Bunga',
            'Jangka Waktu',
            'Tanggal Realisasi (yyyy-mm-dd)',
            'Tanggal Jatuh Tempo (yyyy-mm-dd)',
            'Jenis Agunan',
            'Nilai Agunan',
            'Tenaga Kerja',
            'Jenis Terjamin',
            'Limit Penarikan',
            'SP3',
        ];
    }


    public function registerEvents(): array
    {
        return [];
    }
}
