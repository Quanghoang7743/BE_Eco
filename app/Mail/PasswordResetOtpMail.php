<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $otp,
        public int $expiresInMinutes,
        public string $identity
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Mã xác nhận đặt lại mật khẩu Ecoluck',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password_reset_otp',
            with: [
                'otp' => $this->otp,
                'expiresInMinutes' => $this->expiresInMinutes,
                'identity' => $this->identity,
            ],
        );
    }
}
