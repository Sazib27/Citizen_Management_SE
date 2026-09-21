<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;

    public string $verificationUrl;

    public string $otp;

    public function __construct(
        User $user,
        string $verificationUrl,
        string $otp
    ) {
        $this->user = $user;
        $this->verificationUrl = $verificationUrl;
        $this->otp = $otp;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Verify Your Email Address'
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.email-verification'
        );
    }

    public function attachments(): array
    {
        return [];
    }
}