<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SendOtpToMitra extends Mailable
{
    use Queueable, SerializesModels;

    public $otp;
    public $user_id;

    public function __construct($otp, $user_id)
    {
        $this->otp = $otp;
        $this->user_id = $user_id;
    }

    public function build()
    {
        return $this->subject('Kode OTP Anda')
                ->view('emails.otp')
                ->with([
                    'otp' => $this->otp,
                    'user_id' => $this->user_id
                ]);
    } 
}
