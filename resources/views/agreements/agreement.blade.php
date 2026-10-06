{{-- Franchise agreement sent for e-sign (spec §5.1 step 4). Printed by headless Chromium on A4. --}}
@php
    /** @var array<string, mixed> $document */
    $agreement = $document['agreement'];
    $franchise = $document['franchise'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Agreement {{ $agreement->agreement_no }}</title>
    <style>
        @page { size: A4; margin: 18mm 16mm; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 17px; margin: 0 0 4px; color: #1d4e89; }
        h2 { font-size: 13px; margin: 16px 0 4px; border-bottom: 1px solid #999; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 3px 4px; vertical-align: top; border-bottom: 1px solid #eee; }
        td:first-child { width: 38%; color: #444; }
        .signatures { margin-top: 40px; display: flex; justify-content: space-between; }
        .signatures div { width: 45%; border-top: 1px solid #333; padding-top: 4px; }
    </style>
</head>
<body>
<h1>Franchise Agreement {{ $agreement->agreement_no }}</h1>
<div>Between <strong>{{ $document['brand'] }}</strong> (the head office) and <strong>{{ $franchise->legal_name }}</strong> trading as {{ $franchise->name }} (the franchise).</div>

<h2>Franchise</h2>
<table>
    <tr><td>Franchise code</td><td>{{ $franchise->franchise_code }}</td></tr>
    <tr><td>Owner</td><td>{{ $franchise->owner_name }}</td></tr>
    <tr><td>PAN</td><td>{{ $franchise->pan }}</td></tr>
    @if ($franchise->gstin)<tr><td>GSTIN</td><td>{{ $franchise->gstin }}</td></tr>@endif
    <tr><td>Address</td><td>{{ $franchise->address }}</td></tr>
</table>

<h2>Commercial terms</h2>
<table>
    <tr><td>Franchise model</td><td>{{ $agreement->franchise_model === \App\Modules\Network\Enums\FranchiseModel::Lab ? 'Collection centre with own laboratory' : 'Collection centre (PSC)' }}</td></tr>
    <tr><td>Billing model</td><td>
        @if ($agreement->billing_model === \App\Modules\Network\Enums\BillingModel::RevenueShare)
            Revenue share: the franchise earns {{ $agreement->commission_pct }}% of the net amount it bills
        @else
            Wholesale: tests bought at partner price from a prepaid wallet
        @endif
    </td></tr>
    <tr><td>Franchise fee (one-time)</td><td>Rs {{ $agreement->franchise_fee }}</td></tr>
    <tr><td>Security deposit (refundable)</td><td>Rs {{ $agreement->security_deposit }}</td></tr>
    @if ($agreement->min_monthly_business)<tr><td>Minimum monthly business</td><td>Rs {{ $agreement->min_monthly_business }}</td></tr>@endif
    <tr><td>Settlement cycle</td><td>{{ ucfirst($agreement->settlement_cycle->value) }}</td></tr>
    <tr><td>Term</td><td>{{ $agreement->start_date->format('d M Y') }} to {{ $agreement->end_date->format('d M Y') }}</td></tr>
    <tr><td>Territory</td><td>{{ $agreement->territory ?? '—' }}@if ($document['pincodes'] !== [])<br>Exclusive pincodes: {{ implode(', ', $document['pincodes']) }}@endif</td></tr>
</table>

<div class="signatures">
    <div>For {{ $document['brand'] }}</div>
    <div>For {{ $franchise->legal_name }}</div>
</div>
</body>
</html>
