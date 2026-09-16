<?php

namespace App\Jobs;

use App\Mail\ReconciliationApprovedMail;
use App\Models\FinancialReconciliation;
use App\Models\Reconciliation;
use App\Support\SafeEmail;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SendReconciliationApprovedEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Reconciliation $reconciliation,
    ) {}

    public function handle(): void
    {
        $reconciliation = Reconciliation::query()
            ->with(['store.owner'])
            ->find($this->reconciliation->id);

        if (!$reconciliation) {
            logger()->warning('Reconciliation not found for approval email job: ' . $this->reconciliation->id);
            return;
        }

        // Get related financial reconciliation record
        $financialRecon = FinancialReconciliation::query()
            ->where('store_id', $reconciliation->store_id)
            ->whereDate('period_date', $reconciliation->reconciliation_date)
            ->first();

        // Get store owner email
        $storeOwner = $reconciliation->store?->owner;
        if (! $storeOwner || ! SafeEmail::isValid($storeOwner->email)) {
            logger()->warning('No store owner email found for reconciliation: ' . $reconciliation->id);
            return;
        }
        $storeOwnerEmail = SafeEmail::normalize($storeOwner->email);

        // Generate PDF bill
        $billPdfPath = null;
        try {
            $billPdfPath = $this->generateBillPdf($reconciliation, $financialRecon);
        } catch (\Throwable $ex) {
            logger()->error('Failed to generate bill PDF: ' . $ex->getMessage());
            // Still send email even if PDF generation fails
        }

        // Send email
        try {
            Mail::to($storeOwnerEmail)->send(
                new ReconciliationApprovedMail($reconciliation, $billPdfPath)
            );
            logger()->info('Reconciliation approval email sent to: ' . $storeOwnerEmail);
        } catch (\Throwable $ex) {
            logger()->error('Failed to send reconciliation email: ' . $ex->getMessage());
            throw $ex;
        }
    }

    private function generateBillPdf(Reconciliation $reconciliation, ?FinancialReconciliation $financialRecon): ?string
    {
        if (!$financialRecon) {
            return null;
        }

        $html = view('pdfs.reconciliation_bill', [
            'reconciliation' => $reconciliation,
            'financialRecon' => $financialRecon,
            'store' => $reconciliation->store,
        ])->render();

        $pdf = Pdf::setOptions([
            'defaultFont' => 'DejaVu Sans',
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => true,
        ])->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

        $fileName = 'bill_' . $this->reconciliation->id . '_' . Str::random(8) . '.pdf';
        $path = 'reconciliation_bills/' . $fileName;

        Storage::disk('local')->put($path, $pdf->output());

        return $path;
    }
}
