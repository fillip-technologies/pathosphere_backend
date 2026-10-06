{{-- Lab report PDF (spec §5.5, §10). Printed by headless Chromium on A4. --}}
@php
    /** @var array<string, mixed> $document */
    $order = $document['order'];
    $lab = $document['lab'];
    $centre = $document['collection_centre'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Report {{ $order->orderNo }}</title>
    <style>
        @page { size: A4; margin: 14mm 12mm; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 10.5px; color: #111; }
        header { display: flex; justify-content: space-between; border-bottom: 2px solid #1d4e89; padding-bottom: 6px; }
        header h1 { margin: 0; font-size: 18px; color: #1d4e89; }
        .muted { color: #555; }
        .patient { display: grid; grid-template-columns: repeat(3, 1fr); gap: 2px 12px; margin: 8px 0; padding: 6px; border: 1px solid #ccc; }
        .notice { margin: 6px 0; padding: 4px 6px; border: 1px solid #c77c00; background: #fff6e5; }
        h2 { font-size: 13px; margin: 14px 0 4px; border-bottom: 1px solid #999; }
        h3 { font-size: 11px; margin: 8px 0 2px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; border-bottom: 1px solid #999; font-weight: 600; }
        td, th { padding: 2px 4px; vertical-align: top; }
        .abnormal { font-weight: 700; }
        .signature { margin-top: 8px; text-align: right; }
        .signature img { height: 40px; }
        footer { margin-top: 18px; display: flex; justify-content: space-between; align-items: flex-end; border-top: 1px solid #ccc; padding-top: 6px; }
        footer .qr svg { width: 80px; height: 80px; }
    </style>
</head>
<body>
<header>
    <div>
        <h1>{{ $document['brand'] }}</h1>
        <div>Processed at: <strong>{{ $lab->name }}</strong>, {{ $lab->address }}</div>
        @if ($lab->nablCertificateNo)
            <div class="muted">NABL Certificate {{ $lab->nablCertificateNo }}@if ($lab->nablValidTill) (valid till {{ $lab->nablValidTill->format('d M Y') }})@endif</div>
        @endif
        @if ($lab->clinicalEstablishmentRegNo)
            <div class="muted">Clinical Establishment Reg. {{ $lab->clinicalEstablishmentRegNo }}</div>
        @endif
    </div>
    <div class="muted">Collected at: {{ $centre->name }}</div>
</header>

<section class="patient">
    <div>Patient: <strong>{{ $order->patientName }}</strong></div>
    <div>Age / Gender: {{ $order->ageYears === null ? '-' : $order->ageYears.' Y' }} / {{ ucfirst($order->gender->value) }}</div>
    <div>UHID: {{ $order->uhid }}</div>
    <div>Order No: {{ $order->orderNo }}</div>
    <div>Referred by: {{ $order->doctorName ?? 'Self' }}</div>
    <div>Report version: {{ $document['report']['version'] }}</div>
    <div>Collected: {{ $document['collected_at'] ?? '-' }}</div>
    <div>Received: {{ $document['received_at'] ?? '-' }}</div>
    <div>Reported: {{ $document['report']['released_at'] ?? '-' }}</div>
</section>

@if ($document['report']['amendment_reason'])
    <div class="notice">Amended report. Reason: {{ $document['report']['amendment_reason'] }} This version replaces version {{ $document['report']['version'] - 1 }}.</div>
@endif
@if ($document['report']['is_partial'])
    <div class="notice">Partial report: further results will follow in a later version.</div>
@endif

@foreach ($document['sections'] as $section)
    <h2>{{ $section['department'] }}</h2>
    @foreach ($section['tests'] as $test)
        <h3>{{ $test['name'] }}@if ($test['method']) <span class="muted">({{ $test['method'] }})</span>@endif</h3>
        <table>
            <thead><tr><th>Test</th><th>Result</th><th>Flag</th><th>Unit</th><th>Reference range</th></tr></thead>
            <tbody>
            @foreach ($test['rows'] as $row)
                <tr class="{{ $row['is_abnormal'] ? 'abnormal' : '' }}">
                    <td>{{ $row['name'] }}</td>
                    <td>{{ $row['value'] }}</td>
                    <td>{{ $row['flag'] }}</td>
                    <td>{{ $row['unit'] }}</td>
                    <td>{{ $row['range'] }}</td>
                </tr>
                @if ($row['comment'])
                    <tr><td></td><td colspan="4" class="muted">{{ $row['comment'] }}</td></tr>
                @endif
            @endforeach
            </tbody>
        </table>
    @endforeach
    @if ($section['signature'])
        <div class="signature">
            <img src="{{ $section['signature']['image'] }}" alt="Signature">
            <div><strong>{{ $section['signature']['name'] }}</strong>, {{ $section['signature']['qualification'] }}</div>
            <div class="muted">{{ $section['signature']['registration'] }} &middot; Signed {{ $section['signature']['signed_at'] }}</div>
        </div>
    @endif
@endforeach

<footer>
    <div class="muted">
        Verify this report at {{ $document['verify_url'] }}<br>
        This report is electronically signed. Results relate only to the sample tested.
    </div>
    <div class="qr">{!! $document['qr_svg'] !!}</div>
</footer>
</body>
</html>
