<?php

namespace App\Http\Controllers\SuretyBondTransactionServices;

use App\Http\Controllers\Controller;
use App\Services\SuretyBondServices\SuretyBond as SuretyBondTransactionService;
use Illuminate\Http\Request;


class SuretyBondTransactionController extends Controller
{
    protected $service;

    public function __construct(SuretyBondTransactionService $service)
    {
        $this->service = $service;
    }

    public function show(Request $request)
    {
        $result = $this->service->handleShow($request);

        return response()->json($result['response'], $result['status']);
    }

    public function store(Request $request)
    {
        return $this->service->handleStore($request);
    }
}
