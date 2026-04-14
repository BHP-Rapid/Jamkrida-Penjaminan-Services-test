<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Crypt;
use App\Helpers\AesHelper;

class PenjaminanHdr extends Model
{
    use HasFactory;

    protected $table = 'penjaminan_hdr';
    protected $primaryKey = 'trx_no';
    protected $keyType = 'string';

    protected $fillable = [
        'trx_no',
        'mitra_id',
        'pic_mitra',
        'trx_status',
        'due_date',
        'nik',
        'nama',
        'npwp',
        'no_telp',
        'tmp_lahir',
        'tgl_lahir',
        'alamat',
        'provinsi',
        'kota',
        'kecamatan',
        'kelurahan',
        'jenis_produk',
        'plafon_kredit',
        'nilai_penjaminan',
        'suku_bunga',
        'plafon',
        'tgl_mulai_kredit',
        'jangka_waktu',
        'max_claim',
        'coverage',
        'no_surat_permohonan',
        'jenis_nasabah',
        'jenis_kelamin',
        'role',
        'jenis_kredit',
        'jenis_bond',
        'skema_penalty',
        'jenis_persyaratan',
        'sektor',
        'nama_principal',
        'nama_obligee',
        'nama_proyek',
        'tgl_surat_permohonan',
        'no_surat_perjanjian',
        'jenis_surat_perjanjian',
        'tgl_surat_perjanjian',
        'is_bast',
        'no_surat_bast',
        'tgl_surat_bast',
        'nilai_proyek',
        'nilai_bond',
        'nilai_bond_persentase',
        'period_awal',
        'period_akhir',
        'tarif_percentage',
        'fee_base_percentage',
        'text_percentage_penjaminan_sp',
        'biaya_admin',
        'ijp',
        'penalty',
        'biaya_materai',
        'bank',
        'bank_cabang',
        'pks',
        'jenis_cogar',
        'treaty',
        'booking_nomor_sp',
        'no_rek',
        'loan_number',
        'informasi_agunan',
        'agunan',
        'title',
        'created_by_name',
        'created_by_id',
        'updated_by_name',
        'updated_by_id',
        'status_sync_creatio',
        'status_jaminan',
        'id_upload',
        'jenis_bank_garansi',
        'tanggal_penerbitan',
        'aturan_yang_berlaku',
        'tujuan_bank_garansi',
        'transaksi_pendukung',
        'tanggal_transaksi_pendukung',
        'currency',
        'nama_penerima_jaminan',
        'project_bank_garansi',
        'is_manual',
        'order_id',
        'order_payment_expired_at',
        'order_payment_url',
        'order_payment_token',
    ];

    // protected function nik(): Attribute {
    //     return Attribute::make(
    //         get: fn($value) => $value ? Crypt::decryptString($value) : null,
    //         set: fn($value) => $value ? Crypt::encryptString($value) : null,
    //     );
    // }

    public function payments()
    {
        return $this->hasMany(PenjaminanPayment::class, 'trx_no', 'trx_no');
    }

    public function bulkUpload()
    {
        return $this->belongsTo(BulkUploadPenjaminanHdr::class, 'id_upload', 'id')
            ->withDefault(['upload_id' => null]);
    }

    protected function npwp(): Attribute
    {
        $key = base64_decode(config('services.secure.key'));

        return Attribute::make(
            get: fn($value) => $value ? AesHelper::decrypt($value, $key) : null,
            set: fn($value) => $value ? AesHelper::encrypt($value, $key) : null,
        );
    }

    protected function noRek(): Attribute
    {
        $key = base64_decode(config('services.secure.key'));

        return Attribute::make(
            get: fn($value) => $value ? AesHelper::decrypt($value, $key) : null,
            set: fn($value) => $value ? AesHelper::encrypt($value, $key) : null,
        );
    }
}
