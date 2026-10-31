@php
    use App\Support\DocumentFormat as F;

    $title = $invoice->document_type === 'credit_note' ? 'Notë krediti · Credit note' : ($invoice->kind === 'sale' ? 'Faturë · Invoice' : 'Faturë blerjeje · Purchase bill');
    $paymentMethods = ['bank_transfer' => 'Transfer bankar · Bank transfer', 'cash' => 'Para në dorë · Cash', 'card' => 'Kartelë · Card', 'other' => 'Tjetër · Other'];
    $currency = $invoice->currency;
    $party = $invoice->counterparty;
@endphp
<!DOCTYPE html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} {{ $invoice->invoice_number }}</title>
    @include('pdf.styles')
</head>
<body>
    <table class="layout">
        <tr>
            <td style="width: 60%;">
                <h1>{{ $business->trade_name ?: $business->legal_name }}</h1>
                @if ($business->trade_name)<p>{{ $business->legal_name }}</p>@endif
                <p>NUI {{ $business->nui }}</p>
                @if ($business->address || $business->municipality)<p>{{ collect([$business->address, $business->municipality])->filter()->join(', ') }}</p>@endif
                @if ($business->phone || $business->email)<p>{{ collect([$business->phone, $business->email])->filter()->join(' · ') }}</p>@endif
                @if ($business->vat_status === 'not_registered')<p class="muted">Nuk është i regjistruar për TVSH · Not registered for VAT</p>@endif
            </td>
            <td style="width: 40%; text-align: right;">
                <h2>{{ $title }}</h2>
                <p style="font-size: 13px; font-weight: bold;">{{ $invoice->invoice_number }}</p>
                @if ($invoice->status === 'draft')<span class="badge">DRAFT – nuk është lëshuar · not issued</span>@endif
                @if ($invoice->status === 'cancelled')<span class="badge">ANULUAR · CANCELLED {{ F::date($invoice->cancelled_on?->toDateString()) }}</span>@endif
            </td>
        </tr>
    </table>

    <table class="layout" style="margin: 18px 0 14px;">
        <tr>
            <td style="width: 60%;">
                <h3>{{ $invoice->kind === 'sale' ? 'Blerësi · Bill to' : 'Furnitori · Supplier' }}</h3>
                <p><strong>{{ $party->name }}</strong></p>
                @if ($party->nui)<p>NUI {{ $party->nui }}</p>@endif
                @if ($party->address || $party->municipality)<p>{{ collect([$party->address, $party->municipality])->filter()->join(', ') }}</p>@endif
                @if ($party->email)<p>{{ $party->email }}</p>@endif
            </td>
            <td style="width: 40%;">
                <table>
                    <tr><td class="muted">Data · Date</td><td class="number">{{ F::date($invoice->issued_on?->toDateString()) }}</td></tr>
                    <tr><td class="muted">Afati · Due</td><td class="number">{{ F::date($invoice->due_on?->toDateString()) }}</td></tr>
                    @if ($invoice->payment_method)<tr><td class="muted">Pagesa · Payment</td><td class="number">{{ $paymentMethods[$invoice->payment_method] ?? $invoice->payment_method }}</td></tr>@endif
                    <tr><td class="muted">Valuta · Currency</td><td class="number">{{ $currency }}</td></tr>
                    @if ($invoice->document_type === 'credit_note' && $invoice->creditedInvoice)
                        <tr><td class="muted">Kreditim i faturës · Credits invoice</td><td class="number">{{ $invoice->creditedInvoice->invoice_number }} · {{ F::date($invoice->creditedInvoice->issued_on?->toDateString()) }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <table>
        <thead>
            <tr><th>#</th><th>Përshkrimi · Description</th><th class="number">Sasia · Qty</th><th class="number">Çmimi · Price</th><th class="number">Zbritja · Discount</th><th>TVSH · VAT</th><th class="number">Pa TVSH · Net</th><th class="number">Totali · Total</th></tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr>
                    <td>{{ $line->position }}</td>
                    <td>{{ $line->description }}</td>
                    <td class="number">{{ F::quantity($line->quantity) }}@if ($line->unit) {{ $line->unit }}@endif</td>
                    <td class="number">{{ F::money($line->unit_price) }}</td>
                    <td class="number">{{ (float) $line->discount_amount > 0 ? F::money($line->discount_amount) : '—' }}</td>
                    <td>{{ F::rate($line->tax_snapshot['rate_percent'] ?? 0) }}</td>
                    <td class="number">{{ F::money($line->net_amount) }}</td>
                    <td class="number">{{ F::money($line->gross_amount) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="layout" style="margin-top: 14px;">
        <tr>
            <td style="width: 55%; padding-right: 18px;">
                <table>
                    <thead><tr><th>TVSH · VAT</th><th class="number">Baza · Base</th><th class="number">TVSH · VAT</th></tr></thead>
                    <tbody>
                        @foreach ($vatBreakdown as $group)
                            <tr><td>{{ $group['label'] }} · {{ F::rate($group['rate']) }}</td><td class="number">{{ F::money($group['net']) }}</td><td class="number">{{ F::money($group['tax']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </td>
            <td style="width: 45%;">
                <table>
                    <tr><td>Pa TVSH · Net</td><td class="number">{{ F::money($invoice->net_amount, $currency) }}</td></tr>
                    <tr><td>TVSH · VAT</td><td class="number">{{ F::money($invoice->tax_amount, $currency) }}</td></tr>
                    <tr class="total-row"><td>{{ $invoice->document_type === 'credit_note' ? 'Kredituar · Total credited' : 'Për pagesë · Total due' }}</td><td class="number">{{ F::money($invoice->gross_amount, $currency) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @if ($invoice->kind === 'sale' && $bankAccounts->isNotEmpty())
        <div style="margin-top: 16px;">
            <h3>Llogaritë bankare · Bank accounts</h3>
            @foreach ($bankAccounts as $bankAccount)
                <p>{{ $bankAccount->bank_name }}@if ($bankAccount->account_name) · {{ $bankAccount->account_name }}@endif @if ($bankAccount->iban) · IBAN {{ $bankAccount->iban }}@endif</p>
            @endforeach
        </div>
    @endif

    @if ($invoice->notes)<p style="margin-top: 14px;">{{ $invoice->notes }}</p>@endif
    @if ($invoice->status === 'cancelled' && $invoice->cancellation_reason)<p style="margin-top: 6px;">Arsyeja e anulimit · Cancellation reason: {{ $invoice->cancellation_reason }}</p>@endif

    <div class="footer">{{ $business->legal_name }} · NUI {{ $business->nui }} · {{ $title }} {{ $invoice->invoice_number }}</div>
</body>
</html>
