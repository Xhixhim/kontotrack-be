@php
    use App\Support\DocumentFormat as F;

    $businessName = $business->trade_name ?: $business->legal_name;
    $isCreditNote = $invoice->document_type === 'credit_note';
    $total = F::money($invoice->gross_amount, $invoice->currency);
@endphp
<x-mail::message>
# {{ $businessName }}

@if ($personalMessage)
{{ $personalMessage }}

@endif
@if ($isCreditNote)
Bashkëngjitur gjeni notën e kreditit **{{ $invoice->invoice_number }}** të datës {{ F::date($invoice->issued_on?->toDateString()) }}, me vlerë **{{ $total }}**.

Attached is credit note **{{ $invoice->invoice_number }}** dated {{ F::date($invoice->issued_on?->toDateString()) }}, for **{{ $total }}**.
@else
Bashkëngjitur gjeni faturën **{{ $invoice->invoice_number }}** të datës {{ F::date($invoice->issued_on?->toDateString()) }}, me vlerë **{{ $total }}**@if ($invoice->due_on), me afat pagese {{ F::date($invoice->due_on->toDateString()) }}@endif.

Attached is invoice **{{ $invoice->invoice_number }}** dated {{ F::date($invoice->issued_on?->toDateString()) }}, for **{{ $total }}**@if ($invoice->due_on), due on {{ F::date($invoice->due_on->toDateString()) }}@endif.
@endif

{{ $businessName }}@if ($business->phone) · {{ $business->phone }}@endif @if ($business->email) · {{ $business->email }}@endif

</x-mail::message>
