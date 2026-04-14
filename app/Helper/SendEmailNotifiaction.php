<?php

namespace App\Helpers;

use App\Mail\PaymentSuccessMail;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendEmailNotification
{
    public static function sendEmail(
        string $email,
        string $userName,
        string $noPermohonan,
        string $noTransaction,
        string $date,
        $total
    ): void {
        try {
            $formatTotal = number_format($total, 0);
            $formatDate = Carbon::parse($date)->format('D, d M Y H:i');

            Mail::to($email)->send(
                new PaymentSuccessMail(
                    $userName,
                    $noPermohonan,
                    $noTransaction,
                    $formatDate,
                    $formatTotal
                )
            );
        } catch (\Exception $e) {
            Log::error('Failed send payment email notification', [
                'exception' => $e->getMessage(),
                'email' => $email
            ]);
        }
    }
}