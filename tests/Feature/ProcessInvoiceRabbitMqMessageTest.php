<?php

namespace Tests\Feature;

use App\Events\RabbitMqMessageReceived;
use App\Helpers\AesHelper;
use App\Jobs\HandleRabbitMqMessageJob;
use App\Listeners\ProcessInvoiceRabbitMqMessage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProcessInvoiceRabbitMqMessageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('transaction_penjaminan_header', function (Blueprint $table): void {
            $table->string('trx_no')->primary();
            $table->string('no_surat_permohonan');
        });

        Schema::create('custom_bond_transaction', function (Blueprint $table): void {
            $table->id('id_bond');
            $table->string('trx_no');
        });

        Schema::create('custombond_tenor_schedule', function (Blueprint $table): void {
            $table->id('cstb_schedule_id');
            $table->uuid('external_invoice_id')->nullable()->unique();
            $table->unsignedBigInteger('id_bond');
            $table->unsignedSmallInteger('tenor_sequence')->nullable();
            $table->date('due_date')->nullable();
            $table->string('invoice_number', 30)->nullable();
            $table->decimal('amount', 16, 2)->nullable();
            $table->string('status', 30);
            $table->timestamps();
        });


        Schema::create('surety_bond_transaction', function (Blueprint $table): void {
            $table->id('id_trx_product');
            $table->string('trx_no');
        });

        Schema::create('suretybond_tenor_schedule', function (Blueprint $table): void {
            $table->id('srtb_schedule_id');
            $table->unsignedBigInteger('id_trx_product');
            $table->unsignedSmallInteger('tenor_sequence')->nullable();
            $table->date('due_date')->nullable();
            $table->string('invoice_number', 30)->nullable();
            $table->string('invoice_number_collateral', 30)->nullable();
            $table->decimal('amount', 16, 2)->nullable();
            $table->decimal('collateral_amount', 16, 2)->nullable();
            $table->string('status', 30)->nullable();
            $table->string('status_collateral', 30)->nullable();
            $table->timestamps();
        });
        Schema::create('multiguna_transaction', function (Blueprint $table): void {
            $table->id('id_multiguna');
            $table->string('trx_no');
        });

        Schema::create('multiguna_debitur', function (Blueprint $table): void {
            $table->id('id_trx_debitur');
            $table->unsignedBigInteger('multiguna_trx_id');
            $table->string('no_sp_detail')->nullable();
            $table->text('nik')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('multiguna_tenor_schedule', function (Blueprint $table): void {
            $table->id('schedule_id');
            $table->unsignedBigInteger('id_trx_debitur');
            $table->string('invoice_number', 30)->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedSmallInteger('tenor_sequence')->nullable();
            $table->string('status', 30)->nullable();
            $table->decimal('amount', 16, 2)->nullable();
            $table->timestamps();
        });

        DB::table('transaction_penjaminan_header')->insert([
            [
                'trx_no' => 'TRX-CSTB-001',
                'no_surat_permohonan' => '213',
            ],
            [
                'trx_no' => 'TRX-SRTB-001',
                'no_surat_permohonan' => 'SRTB-001',
            ],
            [
                'trx_no' => 'TRX-MLT-001',
                'no_surat_permohonan' => 'MDWR',
            ],
        ]);

        DB::table('custom_bond_transaction')->insert([
            'id_bond' => 10,
            'trx_no' => 'TRX-CSTB-001',
        ]);

        DB::table('surety_bond_transaction')->insert([
            'id_trx_product' => 20,
            'trx_no' => 'TRX-SRTB-001',
        ]);
        DB::table('multiguna_transaction')->insert([
            'id_multiguna' => 30,
            'trx_no' => 'TRX-MLT-001',
        ]);

        DB::table('multiguna_debitur')->insert([
            'id_trx_debitur' => 40,
            'multiguna_trx_id' => 30,
            'no_sp_detail' => 'MDWR-01',
            'nik' => AesHelper::encrypt('01014945', base64_decode((string) config('services.secure.key'))),
            'is_active' => false,
        ]);
    }

    public function test_it_inserts_each_custom_bond_invoice_as_pending(): void
    {
        app(ProcessInvoiceRabbitMqMessage::class)->handle(
            new RabbitMqMessageReceived($this->customBondPayload())
        );

        $this->assertDatabaseCount('custombond_tenor_schedule', 2);
        $this->assertDatabaseHas('custombond_tenor_schedule', [
            'external_invoice_id' => 'f023abd7-b54b-4b55-b1d3-496c777f174b',
            'id_bond' => 10,
            'tenor_sequence' => 0,
            'invoice_number' => 'INV-00001',
            'amount' => 20000000,
            'due_date' => null,
            'status' => 'Pending',
        ]);
        $this->assertDatabaseHas('custombond_tenor_schedule', [
            'external_invoice_id' => 'a7e6b0c3-8d3a-4d67-9f56-8c23f4a8d901',
            'id_bond' => 10,
            'tenor_sequence' => 0,
            'invoice_number' => 'INV-00002',
            'amount' => 35000000,
            'due_date' => '2026-07-15',
            'status' => 'Pending',
        ]);
    }

    public function test_dispatched_event_processes_grouped_invoice_payload(): void
    {
        event(new RabbitMqMessageReceived($this->customBondPayload()));

        $this->assertDatabaseCount('custombond_tenor_schedule', 2);
        $this->assertDatabaseHas('custombond_tenor_schedule', [
            'external_invoice_id' => 'f023abd7-b54b-4b55-b1d3-496c777f174b',
            'invoice_number' => 'INV-00001',
            'status' => 'Pending',
        ]);
    }

    public function test_it_does_not_update_existing_custom_bond_invoice(): void
    {
        $listener = app(ProcessInvoiceRabbitMqMessage::class);
        $listener->handle(new RabbitMqMessageReceived($this->customBondPayload()));

        $changedPayload = $this->customBondPayload();
        $changedPayload['Data'][0]['Data'][0]['TotalTagihan'] = 99999999;
        $changedPayload['Data'][0]['Data'][0]['TanggalJatuhTempo'] = '2026-08-01T00:00:00';
        $changedPayload['Data'][0]['Data'][0]['Status']['Name'] = 'Lunas';

        $listener->handle(new RabbitMqMessageReceived($changedPayload));

        $this->assertDatabaseCount('custombond_tenor_schedule', 2);
        $this->assertDatabaseHas('custombond_tenor_schedule', [
            'external_invoice_id' => 'f023abd7-b54b-4b55-b1d3-496c777f174b',
            'amount' => 20000000,
            'due_date' => null,
            'status' => 'Pending',
        ]);
    }

    public function test_it_processes_surety_bond_normal_and_collateral_grouped_payload(): void
    {
        app(ProcessInvoiceRabbitMqMessage::class)->handle(
            new RabbitMqMessageReceived($this->suretyBondPayload())
        );

        $this->assertDatabaseCount('suretybond_tenor_schedule', 1);
        $this->assertDatabaseHas('suretybond_tenor_schedule', [
            'id_trx_product' => 20,
            'tenor_sequence' => 0,
            'invoice_number' => 'INV-SRTB1',
            'invoice_number_collateral' => 'INV-SRTC1',
            'amount' => 1200000,
            'collateral_amount' => 450000,
            'due_date' => '2026-07-21',
            'status' => 'Pending',
            'status_collateral' => 'Pending',
        ]);
    }
    public function test_it_processes_creatio_envelope_multiguna_grouped_payload(): void
    {
        app(ProcessInvoiceRabbitMqMessage::class)->handle(
            new RabbitMqMessageReceived($this->multigunaEnvelopePayload())
        );

        $this->assertDatabaseCount('multiguna_tenor_schedule', 1);
        $this->assertDatabaseHas('multiguna_tenor_schedule', [
            'id_trx_debitur' => 40,
            'invoice_number' => 'INV-00001',
            'due_date' => null,
            'invoice_id' => null,
            'tenor_sequence' => 0,
            'status' => 'Pending',
            'amount' => 1000000,
        ]);
        $this->assertDatabaseHas('multiguna_debitur', [
            'id_trx_debitur' => 40,
            'is_active' => 1,
        ]);
    }

    public function test_it_processes_installment_grouped_payload_with_tenor_sequence(): void
    {
        app(ProcessInvoiceRabbitMqMessage::class)->handle(
            new RabbitMqMessageReceived($this->installmentPayload())
        );

        $this->assertDatabaseCount('custombond_tenor_schedule', 1);
        $this->assertDatabaseHas('custombond_tenor_schedule', [
            'external_invoice_id' => '9714c7f1-f80a-4f66-bcbf-bbb02f6d30df',
            'id_bond' => 10,
            'tenor_sequence' => 2,
            'invoice_number' => 'INV-00003',
            'amount' => 15000000,
            'status' => 'Pending',
        ]);

        $this->assertDatabaseCount('multiguna_tenor_schedule', 1);
        $this->assertDatabaseHas('multiguna_tenor_schedule', [
            'id_trx_debitur' => 40,
            'invoice_number' => 'INV-MLT2',
            'tenor_sequence' => 3,
            'amount' => 250000,
            'status' => 'Pending',
        ]);
    }

    public function test_job_processes_payload_without_inbox_table(): void
    {
        (new HandleRabbitMqMessageJob($this->customBondPayload(), [
            'queue' => 'invoice.queue',
            'message_id' => 'msg-001',
        ], json_encode($this->customBondPayload())))->handle();

        $this->assertDatabaseCount('custombond_tenor_schedule', 2);
        $this->assertDatabaseHas('custombond_tenor_schedule', [
            'external_invoice_id' => 'f023abd7-b54b-4b55-b1d3-496c777f174b',
            'invoice_number' => 'INV-00001',
            'status' => 'Pending',
        ]);
    }

    private function installmentPayload(): array
    {
        return [
            'Data' => [
                [
                    'Produk' => 'Custom Bond',
                    'CaraBayar' => 'Installment',
                    'Count' => 1,
                    'Data' => [
                        [
                            'Id' => '9714c7f1-f80a-4f66-bcbf-bbb02f6d30df',
                            'Name' => 'INV-00003',
                            'NomorPermohonan' => '213',
                            'TenorSequence' => 2,
                            'TotalTagihan' => 15000000.00,
                            'Tagihan' => 0.00,
                            'TanggalJatuhTempo' => '2026-08-15T00:00:00',
                            'TanggalPembayaran' => null,
                            'Status' => ['Name' => 'Belum Lunas'],
                        ],
                    ],
                ],
                [
                    'Produk' => 'Multiguna',
                    'CaraBayar' => 'Installment',
                    'Count' => 1,
                    'Data' => [
                        [
                            'Id' => 'd5cd2dfb-ae6a-4ddb-8a5f-a91150d6d6ad',
                            'Name' => 'INV-MLT2',
                            'NomorPermohonan' => 'MDWR',
                            'TotalTagihan' => 250000.00,
                            'Tagihan' => 0.00,
                            'TanggalJatuhTempo' => '2026-08-20T00:00:00',
                            'TanggalPembayaran' => null,
                            'Nasabah' => [
                                'Nik' => '01014945',
                                'Name' => 'Jamaludin',
                                'tenor_sequence' => 3,
                            ],
                            'Status' => ['Name' => 'Belum Lunas'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function customBondPayload(): array
    {
        return [
            'Data' => [
                [
                    'Produk' => 'Custom Bond',
                    'CaraBayar' => 'Full',
                    'Count' => 2,
                    'Data' => [
                        [
                            'Id' => 'f023abd7-b54b-4b55-b1d3-496c777f174b',
                            'Name' => 'INV-00001',
                            'NomorPermohonan' => '213',
                            'TotalTagihan' => 20000000.00,
                            'Tagihan' => 0.00,
                            'TanggalJatuhTempo' => null,
                            'TanggalPembayaran' => null,
                            'Status' => ['Name' => 'Belum Lunas'],
                        ],
                        [
                            'Id' => 'a7e6b0c3-8d3a-4d67-9f56-8c23f4a8d901',
                            'Name' => 'INV-00002',
                            'NomorPermohonan' => '213',
                            'TotalTagihan' => 35000000.00,
                            'Tagihan' => 10000000.00,
                            'TanggalJatuhTempo' => '2026-07-15T00:00:00',
                            'TanggalPembayaran' => null,
                            'Status' => ['Name' => 'Sebagian Dibayar'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function suretyBondPayload(): array
    {
        return [
            'Data' => [
                [
                    'Produk' => 'Surety Bond',
                    'CaraBayar' => 'Full',
                    'Count' => 2,
                    'Data' => [
                        [
                            'Id' => 'd4d7ad12-b728-48b0-b287-9a6d91fb6b91',
                            'Name' => 'INV-SRTB1',
                            'NomorPermohonan' => 'SRTB-001',
                            'TotalTagihan' => 1200000.00,
                            'Tagihan' => 0.00,
                            'TanggalJatuhTempo' => '2026-07-21T00:00:00',
                            'TanggalPembayaran' => null,
                            'TypeInvoice' => ['Name' => 'Penjaminan'],
                            'Status' => ['Name' => 'Belum Lunas'],
                        ],
                        [
                            'Id' => 'be9d63a7-2874-45bb-804e-b6fd223a8d1f',
                            'Name' => 'INV-SRTC1',
                            'NomorPermohonan' => 'SRTB-001',
                            'TotalTagihan' => 450000.00,
                            'Tagihan' => 0.00,
                            'TanggalJatuhTempo' => '2026-07-21T00:00:00',
                            'TanggalPembayaran' => null,
                            'TypeInvoice' => ['Name' => 'Collateral'],
                            'Status' => ['Name' => 'Belum Lunas'],
                        ],
                    ],
                ],
            ],
        ];
    }
    private function multigunaEnvelopePayload(): array
    {
        return [
            'messageId' => '6a11bc2c-64d8-4abb-b383-76519527895b',
            'target' => 'MitraPortal',
            'entityName' => 'Invoice',
            'operation' => 'Grouping',
            'triggerType' => 'Manual',
            'payload' => [
                [
                    'Produk' => 'Multiguna',
                    'CaraBayar' => 'Full',
                    'Count' => 1,
                    'Data' => [
                        [
                            'Id' => '232e4fde-c7de-4180-928c-db236db607f7',
                            'Name' => 'INV-00001',
                            'NomorPermohonan' => 'MDWR',
                            'TotalTagihan' => 1000000.00,
                            'Tagihan' => 0.00,
                            'TanggalJatuhTempo' => null,
                            'TanggalPembayaran' => null,
                            'Nasabah' => [
                                'Nik' => '01014945',
                                'Name' => 'Jamaludin',
                            ],
                            'Mitra' => ['Name' => 'Bank Mandiri'],
                            'Produk' => ['Name' => 'Multiguna'],
                            'CaraBayar' => ['Name' => 'Full'],
                            'Status' => ['Name' => 'Belum Lunas'],
                        ],
                    ],
                ],
            ],
            'fileName' => null,
            'fileContentBase64' => null,
            'timestamp' => '2026-06-23T09:49:12.3297698Z',
            'source' => 'Creatio',
        ];
    }
}
