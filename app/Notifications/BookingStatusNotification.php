<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Notifications\Notification;

class BookingStatusNotification extends Notification
{
    public function __construct(
        private readonly Booking $booking,
        private readonly string $title,
        private readonly string $body,
    ) {}

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
            'title' => $this->title,
            'body' => $this->body,
            'booking_id' => $this->booking->id,
            'billboard_title' => $this->booking->billboard?->title,
            'status' => $this->booking->status,
        ];
    }
}
