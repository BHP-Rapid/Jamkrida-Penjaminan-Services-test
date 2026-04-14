<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PaymentSuccessMail extends Mailable
{
    use Queueable, SerializesModels;

    public $userName;
    public $noPermohonan;
    public $noTransaction;
    public $date;
    public $total;

    public function __construct($userName, $noPermohonan, $noTransaction, $date, $total)
    {
        $this->userName = $userName;
        $this->noPermohonan = $noPermohonan;
        $this->noTransaction = $noTransaction;
        $this->date = $date;
        $this->total = $total;
    }

    public function build()
    {
        $subject = 'Pembayaran No. ' . $this->noPermohonan . ' Telah Berhasil';
        return $this->subject($subject)
            ->view('emails.payment_success')
            ->with([
                'userName' => $this->userName,
                'noPermohonan' => $this->noPermohonan,
                'noTransaction' => $this->noTransaction,
                'date' => $this->date,
                'total' => $this->total
            ]);
    }
}
