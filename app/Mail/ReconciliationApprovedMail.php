<?php

namespace App\Mail;

use App\Models\FinancialReconciliation;
use App\Models\Reconciliation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ReconciliationApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Reconciliation $reconciliation,
        public ?string $billPdfPath = null
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Thông báo] Đối soát giao dịch được duyệt',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reconciliation_approved',
            with: [
                'reconciliation' => $this->reconciliation,
                'store' => $this->reconciliation->store,
            ],
        );
    }

    public function attachments(): array
    {
        $attachments = [];

        if ($this->billPdfPath && Storage::disk('local')->exists($this->billPdfPath)) {
            $attachments[] = Attachment::fromStorageDisk('local', $this->billPdfPath)
                ->as('bill_' . $this->reconciliation->id . '.pdf')
                ->withMime('application/pdf');
        }

        return $attachments;
    }
}
