<?php

namespace App\Notifications;

use App\Models\Billboard;
use Illuminate\Notifications\Notification;

/**
 * Cross-actor (admin + owner) notification for the paid board-listing flow:
 * fee paid -> admins told a board is awaiting review; approved / rejected ->
 * owner told the outcome. Mirrors BookingStatusNotification.
 */
class BillboardListingNotification extends Notification
{
    public function __construct(
        private readonly Billboard $billboard,
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
            'billboard_id' => $this->billboard->id,
            'billboard_title' => $this->billboard->title,
            'listing_status' => $this->billboard->listing_status,
        ];
    }
}
