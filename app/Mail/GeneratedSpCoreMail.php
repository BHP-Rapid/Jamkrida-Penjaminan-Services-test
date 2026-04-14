<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class GeneratedSpCoreMail extends Mailable
{
    use Queueable, SerializesModels;

    public $userName;
    public $trxNo;
    public $noSuratPermohonan;
    public $generatedSp;

    public function __construct($userName, $trxNo, $noSuratPermohonan, $generatedSp)
    {
        $this->userName = $userName;
        $this->trxNo = $trxNo;
        $this->noSuratPermohonan = $noSuratPermohonan;
        $this->generatedSp = $generatedSp;
    }

    public function build()
    {
        return $this->subject("Nomor SP Core Telah Terbit - {$this->generatedSp}")
            ->view('emails.generated_sp_core')
            ->with([
                'userName' => $this->userName,
                'trxNo' => $this->trxNo,
                'noSuratPermohonan' => $this->noSuratPermohonan,
                'generatedSp' => $this->generatedSp,
            ]);
    }
}
