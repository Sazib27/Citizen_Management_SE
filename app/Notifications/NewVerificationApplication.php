<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewVerificationApplication extends Notification
{
    use Queueable;

    public function __construct(public readonly User $applicant)
    {
    }

    /**
     * Deliver via in-app (database) and email channels so the admin both
     * sees the request in the notification center and receives an email.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Payload stored in the admin's notifications table. The admin
     * verification index page can read unread counts via this channel.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type'         => 'verification_application',
            'user_id'      => $this->applicant->id,
            'applicant_id' => $this->applicant->id,
            'name'         => $this->applicant->full_name ?? $this->applicant->name,
            'email'        => $this->applicant->email,
            'nid_number'   => $this->applicant->nid_number,
            'requested_at' => optional($this->applicant->verification_requested_at)->toIso8601String(),
            'message'      => 'A new citizen verification application is awaiting review.',
            'review_url'   => route('admin.verification.show', $this->applicant, false),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New Verification Application Submitted')
            ->line('A new citizen verification application has been submitted.')
            ->line('Applicant: ' . ($this->applicant->full_name ?? $this->applicant->name))
            ->line('NID: ' . ($this->applicant->nid_number ?? 'N/A'))
            ->action('Review Application', route('admin.verification.show', $this->applicant))
            ->line('Please review it at your earliest convenience.');
    }
}
