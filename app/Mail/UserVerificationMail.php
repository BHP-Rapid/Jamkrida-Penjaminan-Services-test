<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;


class UserVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $urlKey;
    public $userName;
    public $userType;
    public $role;

    public function __construct($urlKey, $userName, $userType = null, $role = null)
    {
        $this->urlKey = $urlKey;
        $this->userName = $userName;
        $this->userType = $userType;
        $this->role = $role;
    }
   public function build()
    {
        //$verificationUrl = url("/verify/{$this->urlKey}");
        $frontendUrl = env('FRONTEND_URL', 'http://202.69.100.39:8080');
        $verificationUrl = $frontendUrl . '/reset-password/key=' . $this->urlKey;
        if($this->userType != null && $this->role != null)
        {
            $verificationUrl = $verificationUrl . '?user_type=' . $this->userType . '?role=' . $this->role;
        }

        return $this->subject('Verifikasi Akun Anda')
                    ->view('emails.user_verification')
                    ->with([
                        'verificationUrl' => $verificationUrl,
                        'userName' => $this->userName,
                    ]);
    }
   
}
