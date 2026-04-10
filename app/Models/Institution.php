<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use App\Helpers\AesHelper;
use Illuminate\Support\Facades\Log;

class Institution extends Model
{
    protected $table = 'institution';
    protected $primaryKey = 'id';

    protected $fillable = [
        'institution_id',
        'tenant_id',
        'mitra_id',
        'category',
        'full_name',
        'home_address',
        'home_province',
        'home_city',
        'home_district',
        'home_sub_district',
        'home_zipcode',
        'birth_place',
        'birth_date',
        'gender',
        'mother_name',
        'id_type',
        'id_number',
        'id_issued_location',
        'id_add_type',
        'id_add_number',
        'id_add_issued_location',
        'tax_type',
        'tax_id',
        'job_id',
        'job_level',
        'job_employer_name',
        'job_start_date',
        'job_industry_type',
        'current_salary_amount',
        'current_salary_currency',
        'other_income_source',
        'other_income_type',
        'other_income_currency',
        'other_income_amount',
        'phone_1',
        'phone_2',
        'email_1',
        'email_2',
        'institution_type',
        'institution_address',
        'institution_province',
        'institution_city',
        'institution_district',
        'institution_sub_district',
        'institution_zipcode',
        'omzet_id',
        'omzet_type',
        'omzet_amount',
        'omzet_currency',
        'ops_income_id',
        'ops_type',
        'ops_amount',
        'ops_currency',
        'non_ops_income_id',
        'non_ops_type',
        'non_ops_amount',
        'non_ops_currency',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    protected function birthDate(): Attribute
    {
        $key = base64_decode(config('services.secure.key'));

        return Attribute::make(
            get: fn ($value) => $this->safeDecrypt($value, $key, 'birth_date'),
            set: fn ($value) => $value ? AesHelper::encrypt($value, $key) : null,
        );
    }

    protected function idNumber(): Attribute
    {
        $key = base64_decode(config('services.secure.key'));
        $hashKey = config('services.secure.hash_key');

        return Attribute::make(
            get: fn ($value) => $this->safeDecrypt($value, $key, 'id_number'),
            set: function ($value) use ($key, $hashKey) {
                return [
                    'id_number' => $value ? AesHelper::encrypt($value, $key) : null,
                    'id_number_hash' => $value
                        ? hash_hmac('sha256', $value, $hashKey)
                        : null,
                ];
            }
        );
    }


    protected function idAddNumber() : Attribute
    {

        $key = base64_decode(config('services.secure.key'));

        return Attribute::make(
            get: fn ($value) => $this->safeDecrypt($value, $key, 'id_add_number'),
            set: fn($value) => $value ? AesHelper::encrypt($value, $key) : null,
        );
    }

    protected function taxId() : Attribute
    {

        $key = base64_decode(config('services.secure.key'));

        return Attribute::make(
            get: fn ($value) => $this->safeDecrypt($value, $key, 'tax_id'),
            set: fn($value) => $value ? AesHelper::encrypt($value, $key) : null,
        );
    }

    protected function currentSalaryAmount() : Attribute
    {

        $key = base64_decode(config('services.secure.key'));

        return Attribute::make(
            get: fn ($value) => $this->safeDecrypt($value, $key, 'current_salary_amount'),
            set: fn($value) => $value ? AesHelper::encrypt($value, $key) : null,
        );
    }

    protected function otherIncomeAmount() : Attribute
    {

        $key = base64_decode(config('services.secure.key'));

        return Attribute::make(
            get: fn ($value) => $this->safeDecrypt($value, $key, 'other_income_amount'),
            set: fn($value) => $value ? AesHelper::encrypt($value, $key) : null,
        );
    }

    protected function phone1() : Attribute
    {

        $key = base64_decode(config('services.secure.key'));

        return Attribute::make(
            get: fn ($value) => $this->safeDecrypt($value, $key, 'phone_1'),
            set: fn($value) => $value ? AesHelper::encrypt($value, $key) : null,
        );
    }

    protected function phone2() : Attribute
    {

        $key = base64_decode(config('services.secure.key'));

        return Attribute::make(
            get: fn ($value) => $this->safeDecrypt($value, $key, 'phone_2'),
            set: fn($value) => $value ? AesHelper::encrypt($value, $key) : null,
        );
    }

    protected function email1() : Attribute
    {

        $key = base64_decode(config('services.secure.key'));

        return Attribute::make(
            get: fn ($value) => $this->safeDecrypt($value, $key, 'email_1'),
            set: fn($value) => $value ? AesHelper::encrypt($value, $key) : null,
        );
    }

    protected function email2() : Attribute
    {

        $key = base64_decode(config('services.secure.key'));

        return Attribute::make(
            get: fn($value) => $this->safeDecrypt($value, $key, 'email_2'),
            set: fn($value) => $value ? AesHelper::encrypt($value, $key) : null,
        );
    }

    protected function omzetAmount() : Attribute
    {

        $key = base64_decode(config('services.secure.key'));

        return Attribute::make(
            get: fn ($value) => $this->safeDecrypt($value, $key, 'omzet_amount'),
            set: fn($value) => $value ? AesHelper::encrypt($value, $key) : null,
        );
    }

    protected function nonOpsAmount() : Attribute
    {

        $key = base64_decode(config('services.secure.key'));

        return Attribute::make(
            get: fn ($value) => $this->safeDecrypt($value, $key, 'non_ops_amount'),
            set: fn($value) => $value ? AesHelper::encrypt($value, $key) : null,
        );
    }

    private function safeDecrypt($value, $key, $field = null)
    {
        if (!$value) return null;

        try {
            return AesHelper::decrypt($value, $key, $field);
        } catch (\Throwable $e) {
            Log::error('Decrypt failed on field', [
                'field' => $field,
                'value' => substr((string)$value, 0, 30),
            ]);
            return $value;
        }
    }
}
