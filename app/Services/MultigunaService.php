<?php

namespace App\Services;

use App\Repositories\MultigunaRepository;
use Illuminate\Support\Facades\Validator;

class MultigunaService
{
    public function __construct(
        protected MultigunaRepository $repository
    ) {}

    public function getList(array $params)
    {
        $validator = Validator::make($params, [
            'sort' => 'nullable|string|in:asc,desc',
            'sort_column' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'show_page' => 'nullable|integer|min:1',
            'filter' => 'nullable|array',
            'mitra_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            throw new \Exception($validator->errors()->first());
        }
        return $this->repository->getTransactionList($params);
    }
}