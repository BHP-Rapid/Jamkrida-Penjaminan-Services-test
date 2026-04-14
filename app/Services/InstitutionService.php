<?php

namespace App\Services;

use App\Helpers\AesHelper;
use App\Models\Institution;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InstitutionService
{
    protected $institutionId;
    protected $exceptionMessage;

    public function __construct() {
        $this->institutionId = "";
        $this->exceptionMessage = "";
    }

    public function insertInstitution(array $payload, string $creatorId)
    {
        $rules = [
            'tenant_id' => 'nullable|string|min:1|max:64',
            'mitra_id' => 'nullable|string|min:1|max:64',
            'category' => 'required|string|in:P,L,US',
            // 'full_name' => 'required|string|min:1|max:64',
            'birth_place' => 'required|string|max:64',
            'birth_date' => 'required|date',
            'home_address' => 'required|string|max:255',
            'home_province' => 'required|string|max:64',
            'home_city' => 'required|string|max:64',
            'home_district' => 'required|string|max:64',
            'home_sub_district' => 'required|string|max:64',
            'home_zipcode' => 'required|string|max:10',
            'id_type' => 'required|string|max:20',
            'id_number' => 'required|string|max:64',
            'id_issued_location' => 'required|string|max:64',
            'phone_type' => 'required|string|max:32',
            'phone_1' => 'required|string|max:32',
            'email_1' => 'required|string|max:64'
        ];

        $validator = Validator::make($payload, $rules);
        if($validator->fails())
        {
            throw new ValidationException($validator);
        }

        switch($payload["category"])
        {
            case "P":
                $rulePerorangan = [
                    'gender' => 'required|string|max:1',
                    'mother_name' => 'required|string|max:64',
                    'tax_type' => 'required|string|max:55',
                    'tax_id' => 'required|string|max:64',
                    'job_id' => 'required|string|max:55',
                    'job_level' => 'required|string|max:55',
                    'job_employer_name' => 'required|string|max:40',
                    'job_start_date' => 'required|date',
                    'job_industry_type' => 'required|string|max:55',
                    'current_salary_amount' => 'required|string|max:64',
                    'current_salary_currency' => 'required|string|max:55',
                    'other_income_source' => 'required|string|max:30',
                    'other_income_type' => 'required|string|max:55',
                    'other_income_currency' => 'required|string|max:55',
                    'other_income_amount' => 'required|string|max:64'
                ];
                $peroranganValidator = Validator::make($payload, $rulePerorangan);
                if($peroranganValidator->fails())
                {
                    throw new ValidationException($peroranganValidator);
                }
                break;
            case "L":
                $ruleLembaga = [
                    'institution_type' => 'required|string|max:20',
                    'institution_address' => 'nullable|string|max:150',
                    'institution_province' => 'nullable|string|max:64',
                    'institution_city' => 'nullable|string|max:64',
                    'institution_district' => 'nullable|string|max:64',
                    'institution_sub_district' => 'nullable|string|max:64',
                    'institution_zipcode' => 'nullable|string|max:10',
                    'ops_income_id' => 'required|string|max:55',
                    'ops_type' => 'required|string|max:20',
                    'ops_currency' => 'required|string|max:55',
                    'ops_amount' => 'required|numeric|min:1000',
                    'non_ops_income_id' => 'nullable|string|max:55',
                    'non_ops_type' => 'nullable|string|max:20',
                    'non_ops_currency' => 'nullable|string|max:55',
                    'non_ops_amount' => 'nullable|numeric|min:1000',
                    'phone_2' => 'nullable|string|max:64'
                ];
                $lembagaValidator = Validator::make($payload, $ruleLembaga);
                if($lembagaValidator->fails())
                {
                    throw new ValidationException($lembagaValidator);
                }
                break;
            case "US":
                $ruleUnitUsaha = [
                    'institution_type' => 'required|string|max:20',
                    'id_add_type' => 'nullable|string|max:20',
                    'id_add_number' => 'nullable|string|max:64',
                    'omzet_id' => 'required|string|max:55',
                    'omzet_type' => 'required|string|max:20',
                    'omzet_currency' => 'required|string|max:55',
                    'omzet_amount' => 'required|numeric|min:1000',
                    'ops_income_id' => 'required|string|max:55',
                    'ops_type' => 'required|string|max:20',
                    'ops_currency' => 'required|string|max:55',
                    'ops_amount' => 'required|numeric|min:1000',
                    'non_ops_income_id' => 'nullable|string|max:55',
                    'non_ops_type' => 'nullable|string|max:20',
                    'non_ops_currency' => 'nullable|string|max:55',
                    'non_ops_amount' => 'nullable|numeric|min:1000',
                ];
                $unitUsahaValidator = Validator::make($payload, $ruleUnitUsaha);
                if($unitUsahaValidator->fails())
                {
                    throw new ValidationException($unitUsahaValidator);
                }
                break;
            default:
                throw new Exception("Invalid category.");
        }

        DB::beginTransaction();
        try
        {
            $fallback = function(string $key, $default = null) use($payload) {
                if(!array_key_exists($key, $payload) || $payload[$key] == null || empty($payload[$key]))
                {
                    return $default;
                }
                return $payload[$key];
            };

            // $aesKey = base64_decode(config('services.secure.key'));

            // $phone1New = $fallback('phone_type', '-') . ',' . $fallback('phone_1', '');
            // dd($phone1New);
            // $encryptedPhone = AesHelper::encrypt($phone1New, $aesKey);
            // $encryptedPhone = AesHelper::encrypt($payload['email_1'], $aesKey);
            // dd(strlen($encryptedPhone));

            $insertData = [
                'institution_id' => (string) Str::uuid(),
                'tenant_id' => $fallback('tenant_id', '0'),
                'mitra_id' => $fallback('mitra_id', '0'),
                'category' => $payload['category'],
                'full_name' => $payload['full_name'],
                'home_address' => $fallback('home_address'),
                'home_province' => $fallback('home_province'),
                'home_city' => $fallback('home_city'),
                'home_district' => $fallback('home_district'),
                'home_sub_district' => $fallback('home_sub_district'),
                'home_zipcode' => $fallback('home_zipcode'),
                'birth_place' => $fallback('birth_place'),
                'birth_date' => $fallback('birth_date'),
                'gender' => $fallback('gender'),
                'mother_name' => $fallback('mother_name'),
                'id_type' => $fallback('id_type'),
                'id_number' => $fallback('id_number'),
                'id_issued_location' => $fallback('id_issued_location'),
                'id_add_type' => $fallback('id_add_type', '-'),
                'id_add_number' => $fallback('id_add_number'),
                'id_add_issued_location' => $fallback('id_add_issued_location', '-'),
                'tax_type' => $fallback('tax_type'),
                'tax_id' => $fallback('tax_id'),
                'job_id' => $fallback('job_id'),
                'job_level' => $fallback('job_level'),
                'job_employer_name' => $fallback('job_employer_name'),
                'job_start_date' => $fallback('job_start_date'),
                'job_industry_type' => $fallback('job_industry_type'),
                'current_salary_amount' => $fallback('current_salary_amount'),
                'current_salary_currency' => $fallback('current_salary_currency'),
                'other_income_source' => $fallback('other_income_source'),
                'other_income_type' => $fallback('other_income_type'),
                'other_income_currency' => $fallback('other_income_currency'),
                'other_income_amount' => $fallback('other_income_amount'),
                'phone_1' => $fallback('phone_type', '-') . ',' . $fallback('phone_1', ''),
                'phone_2' => $fallback('phone_2'),
                'email_1' => $fallback('email_1'),
                'email_2' => $fallback('email_2'),
                'institution_type' => $fallback('institution_type'),
                'institution_address' => $fallback('institution_address'),
                'institution_province' => $fallback('institution_province'),
                'institution_city' => $fallback('institution_city'),
                'institution_district' => $fallback('institution_district'),
                'institution_sub_district' => $fallback('institution_sub_district'),
                'institution_zipcode' => $fallback('institution_zipcode'),
                'omzet_id' => $fallback('omzet_id'),
                'omzet_type' => $fallback('omzet_type'),
                'omzet_amount' => $fallback('omzet_amount'),
                'omzet_currency' => $fallback('omzet_currency'),
                'ops_income_id' => $fallback('ops_income_id'),
                'ops_type' => $fallback('ops_type'),
                'ops_amount' => $fallback('ops_amount'),
                'ops_currency' => $fallback('ops_currency'),
                'non_ops_income_id' => $fallback('non_ops_income_id'),
                'non_ops_type' => $fallback('non_ops_type'),
                'non_ops_amount' => $fallback('non_ops_amount'),
                'non_ops_currency' => $fallback('non_ops_currency'),
                'created_by' => $creatorId,
                'created_at' => now()
            ];

            $newInstitution = Institution::create($insertData);
            DB::commit();
            $this->institutionId = $newInstitution->institution_id;
        }
        catch(Exception $e)
        {
            DB::rollBack();
            Log::error("Error inserting institution.", ['exception' => $e]);
            throw $e;
        }
    }

    public function updateInstitution(array $payload, string $updateUserId)
    {
        $rules = [
            'institution_id' => 'required|string',
            'birth_place' => 'nullable|string|max:64',
            'birth_date' => 'nullable|date',
            'home_address' => 'nullable|string|max:255',
            'home_province' => 'nullable|string|max:64',
            'home_city' => 'nullable|string|max:64',
            'home_district' => 'nullable|string|max:64',
            'home_sub_district' => 'nullable|string|max:64',
            'home_zipcode' => 'nullable|string|max:10',
            'id_type' => 'nullable|string|max:55',
            'id_number' => 'nullable|string|max:64',
            'id_issued_location' => 'nullable|string|max:64',
            'phone_type' => 'nullable|string|max:32',
            'phone_1' => 'nullable|string|max:32',
            'email_1' => 'nullable|string|max:64'
        ];

        $validator = Validator::make($payload, $rules);
        if($validator->fails())
        {
            throw new ValidationException($validator);
        }

        $institutionCategory = null;
        $institutionData = Institution::where('institution_id', $payload['institution_id'])
            ->select('category')->first();
        $institutionCategory = $institutionData ? $institutionData->category : null;

        switch($institutionCategory)
        {
            case "P":
                $rulePerorangan = [
                    'gender' => 'nullable|string|max:1',
                    'mother_name' => 'nullable|string|max:64',
                    'tax_type' => 'nullable|string|max:55',
                    'tax_id' => 'nullable|string|max:64',
                    'job_id' => 'nullable|string|max:55',
                    'job_level' => 'nullable|string|max:55',
                    'job_employer_name' => 'nullable|string|max:40',
                    'job_start_date' => 'nullable|date',
                    'job_industry_type' => 'nullable|string|max:55',
                    'current_salary_amount' => 'nullable|string|max:64',
                    'current_salary_currency' => 'nullable|string|max:55',
                    'other_income_source' => 'nullable|string|max:30',
                    'other_income_type' => 'nullable|string|max:55',
                    'other_income_currency' => 'nullable|string|max:55',
                    'other_income_amount' => 'nullable|string|max:64'
                ];
                $peroranganValidator = Validator::make($payload, $rulePerorangan);
                if($peroranganValidator->fails())
                {
                    throw new ValidationException($peroranganValidator);
                }
                break;
            case "L":
                $ruleLembaga = [
                    'institution_type' => 'nullable|string|max:55',
                    'institution_address' => 'nullable|string|max:150',
                    'institution_province' => 'nullable|string|max:64',
                    'institution_city' => 'nullable|string|max:64',
                    'institution_district' => 'nullable|string|max:64',
                    'institution_sub_district' => 'nullable|string|max:64',
                    'institution_zipcode' => 'nullable|string|max:10',
                    'ops_income_id' => 'nullable|string|max:55',
                    'ops_type' => 'nullable|string|max:55',
                    'ops_currency' => 'nullable|string|max:55',
                    'ops_amount' => 'nullable|numeric|min:1000',
                    'non_ops_income_id' => 'nullable|string|max:55',
                    'non_ops_type' => 'nullable|string|max:55',
                    'non_ops_currency' => 'nullable|string|max:55',
                    'non_ops_amount' => 'nullable|numeric|min:1000',
                    'phone_2' => 'nullable|string|max:64'
                ];
                $lembagaValidator = Validator::make($payload, $ruleLembaga);
                if($lembagaValidator->fails())
                {
                    throw new ValidationException($lembagaValidator);
                }
                break;
            case "US":
                $ruleUnitUsaha = [
                    'institution_type' => 'nullable|string|max:55',
                    'id_add_type' => 'nullable|string|max:55',
                    'id_add_number' => 'nullable|string|max:64',
                    'omzet_id' => 'nullable|string|max:55',
                    'omzet_type' => 'nullable|string|max:55',
                    'omzet_currency' => 'nullable|string|max:55',
                    'omzet_amount' => 'nullable|numeric|min:1000',
                    'ops_income_id' => 'nullable|string|max:55',
                    'ops_type' => 'nullable|string|max:55',
                    'ops_currency' => 'nullable|string|max:55',
                    'ops_amount' => 'nullable|numeric|min:1000',
                    'non_ops_income_id' => 'nullable|string|max:55',
                    'non_ops_type' => 'nullable|string|max:55',
                    'non_ops_currency' => 'nullable|string|max:55',
                    'non_ops_amount' => 'nullable|numeric|min:1000',
                ];
                $unitUsahaValidator = Validator::make($payload, $ruleUnitUsaha);
                if($unitUsahaValidator->fails())
                {
                    throw new ValidationException($unitUsahaValidator);
                }
                break;
            default:
                throw new Exception("Invalid category.");
        }

        try
        {
            $updateInstitutionId = $payload['institution_id'];
            if(array_key_exists('phone_type', $payload) && array_key_exists('phone_1', $payload))
            {
                $newPhone = !empty($payload['phone_type']) &&
                    !empty($payload['phone_1']) ?
                        $payload['phone_type'] . ',' . $payload['phone_1']
                        : null;
                unset($payload['phone_key']);
                unset($payload['phone_1']);
                $payload['phone_1'] = $newPhone;
            }
            unset($payload['institution_id']);
            if(array_key_exists('category', $payload))
            {
                unset($payload['category']);
            }

            $encryptKey = base64_decode(config('services.secure.key'));

            $encryptField = [
                'full_name',
                'birth_date',
                'id_number',
                'id_add_number',
                'tax_id',
                'current_salary_amount',
                'other_income_amount',
                'phone_1',
                'phone_2',
                'email_1',
                'email_2',
                'omzet_amount',
                'non_ops_amount'
            ];

            foreach($encryptField as $field) {
                if(array_key_exists($field, $payload) &&
                    !empty($payload[$field])) {
                        $payload[$field] = AesHelper::encrypt($payload[$field], $encryptKey);

                }
            }

            $payload['updated_by'] = $updateUserId;
            $payload['updated_at'] = now();
            Institution::where('institution_id', $updateInstitutionId)
                ->update($payload);
        }
        catch(Exception $e)
        {
            $this->exceptionMessage = "Error updating institution.";
            throw $e;
        }
    }

    public function getCreatedInstitutionId()
    {
        return $this->institutionId;
    }

    public function getExceptionMessage()
    {
        return $this->exceptionMessage;
    }
}
