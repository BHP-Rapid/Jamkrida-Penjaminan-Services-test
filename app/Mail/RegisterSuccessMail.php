<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RegisterSuccessMail extends Mailable
{
    use Queueable, SerializesModels;

    public $name;
     public $user_id;

    public function __construct($name, $user_id)
    {
        $this->name = $name;
        $this->user_id = $user_id;
    }

    public function build()
    {
        $frontendUrl = env('FRONTEND_URL', 'http://202.69.100.39:8080');
        $loginAdminUrl = $frontendUrl . '/login';

        return $this->subject('Registrasi Admin Berhasil')
                    ->view('emails.register_success')
                    ->with([
                        'loginAdminUrl' => $loginAdminUrl,
                    ]);
    }
}
