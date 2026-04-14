<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DendaReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public $userName;
    public $trxNo;
    public $orderId;
    public $dueDate;
    public $daysLate;
    public $penaltyPerDay;
    public $totalDenda;


    public function __construct($userName, $trxNo, $orderId, $dueDate, $daysLate, $penaltyPerDay, $totalDenda)
    {
        $this->userName = $userName;
        $this->trxNo = $trxNo;
        $this->orderId = $orderId;
        $this->dueDate = $dueDate;
        $this->daysLate = $daysLate;
        $this->penaltyPerDay = $penaltyPerDay;
        $this->totalDenda = $totalDenda;
    }


    public function build()
    {
        $subject = "Pemberitahuan Denda - Order {$this->orderId} Terlambat {$this->daysLate} Hari";

        return $this->subject($subject)
            ->view('emails.denda_reminder')
            ->with([
                'userName' => $this->userName,
                'trxNo' => $this->trxNo,
                'orderId' => $this->orderId,
                'dueDate' => $this->dueDate,
                'daysLate' => $this->daysLate,
                'penaltyPerDay' => $this->penaltyPerDay,
                'totalDenda' => $this->totalDenda,
            ]);
    }
}
