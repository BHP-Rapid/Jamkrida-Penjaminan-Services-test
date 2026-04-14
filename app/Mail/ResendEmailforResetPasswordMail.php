<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;


class ResendEmailforResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public $urlKey;
    public $userName;
    public $role;
    public $userType;

    public function __construct($urlKey, $userName, $role, $userType)
    {
        $this->urlKey = $urlKey;
        $this->userName = $userName;
        $this->role = $role;
        $this->userType = $userType;
    }
    public function build()
    {
        //$verificationUrl = url("/verify/{$this->urlKey}");
        $frontendUrl = env('FRONTEND_URL', 'http://202.69.100.39:8080');
        $resetPasswordUrl = $frontendUrl . '/reset-password/key=' . $this->urlKey . '?user_type=' . $this->userType . '?role=' . $this->role;

        if ($this->userType === 'admin') {
            return $this->subject('Reset Password Akun Admin')
                ->view('emails.resend_email_admin')
                ->with([
                    'resetPasswordUrl' => $resetPasswordUrl,
                    'userName' => $this->userName,
                ]);
        } else {
            return $this->subject('Reset Password Akun Mitra')
                ->view('emails.resend_email_mitra')
                ->with([
                    'resetPasswordUrl' => $resetPasswordUrl,
                    'userName' => $this->userName,
                ]);
        }
    }
}
