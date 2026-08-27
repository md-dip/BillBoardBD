<?php

namespace App\Notifications;

use App\Models\Payout;
use Illuminate\Notifications\Notification;

class PayoutNotification extends Notification
{
    public function __construct(private readonly Payout $payout) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Payout sent',
            'body' => "You've been paid out ৳".number_format((float) $this->payout->amount, 2)." for your settled bookings.",
            'payout_id' => $this->payout->id,
            'amount' => $this->payout->amount,
        ];
    }
}
