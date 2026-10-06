{{-- Settlement statement sent to the partner (spec §7.8 statement_pdf_path). Printed by headless Chromium on A4. --}}
@php
    /** @var array<string, mixed> $document */
    $settlement = $document['settlement'];
    $partner = $document['partner'];
    $direction = match ($settlement->direction) {
        \App\Modules\Ledger\Enums\SettlementDirection::PartnerPaysHq => 'Payable by '.$partner->name.' to '.$document['brand'],
        \App\Modules\Ledger\Enums\SettlementDirection::HqPaysPartner => 'Payable by '.$document['brand'].' to '.$partner->name,
        \App\Modules\Ledger\Enums\SettlementDirection::Nil => 'Nothing to pay this cycle',
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Statement {{ $settlement->settlement_no }}</title>
    <style>
        @page { size: A4; margin: 14mm 12mm; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 10.5px; color: #111; }
        h1 { font-size: 17px; margin: 0; color: #1d4e89; }
        h2 { font-size: 12px; margin: 14px 0 4px; border-bottom: 1px solid #999; }
        .muted { color: #555; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; border-bottom: 1px solid #999; font-weight: 600; }
        td, th { padding: 2px 4px; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .summary td:first-child { width: 45%; color: #444; }
        .total { font-weight: 700; border-top: 1px solid #333; }
    </style>
</head>
<body>
<h1>{{ $document['brand'] }}: settlement statement {{ $settlement->settlement_no }}</h1>
<div>{{ $partner->name }} ({{ $partner->code }}) &middot; {{ $settlement->period_start->format('d M Y') }} to {{ $settlement->period_end->format('d M Y') }}</div>
@if ($document['agreement_no'])<div class="muted">Agreement {{ $document['agreement_no'] }}</div>@endif

<h2>Summary</h2>
<table class="summary">
    <tr><td>Gross billing</td><td class="num">Rs {{ $settlement->gross_billing }}</td></tr>
    <tr><td>Partner share</td><td class="num">Rs {{ $settlement->partner_share }}</td></tr>
    <tr><td>Head office share</td><td class="num">Rs {{ $settlement->hq_share }}</td></tr>
    <tr><td>Tax</td><td class="num">Rs {{ $settlement->tax }}</td></tr>
    <tr><td>Account balance at period end</td><td class="num">Rs {{ $settlement->closing_balance }}</td></tr>
    <tr class="total"><td>{{ $direction }}</td><td class="num">Rs {{ $settlement->net_amount }}</td></tr>
</table>

<h2>Account entries</h2>
<table>
    <thead><tr><th>Date</th><th>Entry</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead>
    <tbody>
    @foreach ($document['entries'] as $entry)
        <tr>
            <td>{{ $entry->created_at->setTimezone('Asia/Kolkata')->format('d M Y') }}</td>
            <td>{{ $entry->narration }}</td>
            <td class="num">{{ $entry->debit->isZero() ? '' : $entry->debit }}</td>
            <td class="num">{{ $entry->credit->isZero() ? '' : $entry->credit }}</td>
            <td class="num">{{ $entry->balance_after }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
<p class="muted">Debit: owed by the partner to the head office. Credit: owed by the head office to the partner. Balance after each entry is credit minus debit.</p>
</body>
</html>
