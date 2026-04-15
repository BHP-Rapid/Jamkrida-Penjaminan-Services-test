<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class ExcelDataNormatifKKPBJExport implements FromCollection, WithHeadings, WithEvents, ShouldAutoSize
{
    use Exportable;

    public function collection()
    {
        return collect([
            [
                1,
                'Budi',
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
                '2387654873458734',
                '01.234.567.8-901.000',
                '',
                '2387654873458734',
                'Wiraswasta',
                'Pemilik',
                'CV Sinar Jaya',
                '2010-06-01',
                'IND-045',
                6000000,
                'IDR',
                'Modal kerja dan pembelian mesin',
                50000000,
                12,
                12,
                '2026-11-01',
                '2028-11-01',
                1,
                60000000,
                3,
                2,
                'PT Maju Jaya Abadi',
                'Jl. Sudirman No. 123, Jakarta',
                '1000000000',
                '800000000',
                '24',
                '2026-01-15',
                '2028-01-15',
                '10.5',
                '2026-01-10',
                'Pembangunan Gedung Perkantoran',
                '2000000000',
                '1000000000',
                '100000000',
                '18',
                'MEMO-001/III/2026',
                '2026-01-05',
                '50',
                '2.5',
                'LN-2026-0001'

            ],
            [
                2,
                'Doricky',
                '+62-813-9876-5432',
                'siti.nurhayati@example.com',
                'Jawa Timur',
                'Surabaya',
                'Genteng',
                'Kedungdoro',
                '60275',
                '1990-07-25',
                'Surabaya',
                'P',
                'Rohayah',
                'KTP',
                '1231231231231231',
                '02.345.678.9-012.000',
                '',
                '1231231231231231',
                'Usaha Dagang',
                'Manajer Operasional',
                'PT Mitra Dagang',
                '2015-01-15',
                'IND-110',
                12000000,
                'IDR',
                'Ekspansi cabang dan persediaan',
                150000000,
                10,
                24,
                '2026-10-15',
                '2030-10-15',
                4,
                200000000,
                8,
                3,
                'CV Sukses Makmur',
                'Jl. Diponegoro No. 45, Bandung',
                '750000000',
                '600000000',
                '12',
                '2026-02-01',
                '2027-02-01',
                '9.75',
                '2026-01-28',
                'Proyek Renovasi Sekolah',
                '1200000000',
                '750000000',
                '75000000',
                '12',
                'MEMO-002/III/2026',
                '2026-01-25',
                '30',
                '2',
                'LN-2026-0002'

            ],
        ]);
    }

    public function headings(): array
    {
        return [
            'No.',
            'Nama Makful Anhu',
            'Nomor Telepon',
            'Email',
            'Provinsi Makful Anhu',
            'Kota/Kabupaten Makful Anhu',
            'Kecamatan Makful Anhu',
            'Kelurahan Makful Anhu',
            'kode Pos Makful Anhu',
            'Tanggal Lahir',
            'Tempat Lahir',
            'Jenis Kelamin',
            'Nama Ibu Kandungan',
            'Jenis Identitas',
            'Nomor Identitas 1',
            'NPWP (Giro)',
            'Nomor Identitas 2',
            'NIK',
            'Kategori Pekerjaan',
            'Jabatan',
            'Nama Pemberi Kerja',
            'Tanggal Mulai Bekerja',
            'Kode Industri Internal Pemberi Kerja',
            'Gaji Sekarang',
            'Kode Valuta IDR/USD',
            'Penggunaan Pembiayaan',
            'Plafond Pembiayaan (Rp)',
            'Margin/Bagi Hasil/Ujrah (Thn)',
            'JW Bulan',
            'Tanggal Realisasi (yyyy-mm-dd)',
            'Tanggal Jatuh Tempo (yyyy-mm-dd)',
            'Jenis Agunan (1 = fidusia, 2= apht, 3 = cessie, 4 = gadai)',
            'Nilai Agunan',
            'Tenaga Kerja',
            'Jenis Makful Anhu (0=Perorangan, 1= Usaha Mikro, 2 = Usaha Kecil, 3 = Usaha Menegah, 4 = Koperasi, 5= ritel, 9=lainny)',
            'nama_nasabah',
            'alamat_nasabah',
            'plafond_kredit',
            'nilai_penjaminan',
            'jangka_waktu',
            'tanggal_realisasi',
            'tanggal_jatuh_tempo',
            'suku_bunga',
            'tanggal_kontrak',
            'nama_proyek',
            'nilai_proyek',
            'nilai_kredit_per_proyek',
            'dana_diendapkan',
            'jangka_waktu_proyek',
            'nomor_memo',
            'tanggal_memo',
            'tenaga_kerja',
            'ijp',
            'loan_number'
        ];
    }


    public function registerEvents(): array
    {
        return [];
    }
}
