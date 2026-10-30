<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt verification — {{ $verification['receipt_number'] }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; min-height: 100vh; display: grid; place-items: center; background: #f3f4f6; color: #111827; font: 16px/1.5 Arial, Helvetica, sans-serif; }
        main { width: 100%; max-width: 480px; background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 28px; box-shadow: 0 12px 36px rgba(17, 24, 39, .08); }
        .brand { color: #6b7280; font-size: 12px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
        h1 { font-size: 23px; margin: 8px 0 4px; }
        .status { display: inline-block; margin: 14px 0 20px; padding: 6px 10px; border-radius: 999px; font-weight: 700; font-size: 13px; }
        .verified { color: #166534; background: #dcfce7; }
        .voided { color: #991b1b; background: #fee2e2; }
        .integrity_check_failed, .not_issued { color: #92400e; background: #fef3c7; }
        dl { margin: 0; }
        .row { display: flex; justify-content: space-between; gap: 20px; padding: 12px 0; border-top: 1px solid #e5e7eb; }
        dt { color: #6b7280; }
        dd { margin: 0; text-align: right; font-weight: 600; overflow-wrap: anywhere; }
        .note { margin-top: 20px; color: #6b7280; font-size: 12px; }
    </style>
</head>
<body>
<main>
    <div class="brand">Account · Receipt verification</div>
    <h1>{{ $verification['business_name'] ?: 'Business receipt' }}</h1>
    <div class="status {{ $verification['verification_status'] }}">
        @switch($verification['verification_status'])
            @case('verified')
                Authentic receipt
                @break
            @case('voided')
                Receipt marked void
                @break
            @case('not_issued')
                Receipt has not been issued
                @break
            @default
                Integrity check failed
        @endswitch
    </div>
    <dl>
        <div class="row"><dt>Receipt number</dt><dd>{{ $verification['receipt_number'] }}</dd></div>
        <div class="row"><dt>Receipt type</dt><dd>{{ ucfirst($verification['receipt_type']) }}</dd></div>
        <div class="row"><dt>Transaction status</dt><dd>{{ $verification['transaction_status'] ? ucfirst(str_replace('_', ' ', $verification['transaction_status'])) : '—' }}</dd></div>
        <div class="row"><dt>Amount</dt><dd>{{ $verification['currency'] ? $verification['currency'] . ' ' : '' }}{{ $verification['amount'] ?? '—' }}</dd></div>
        <div class="row"><dt>Issued</dt><dd>{{ $verification['issued_at'] ? \Illuminate\Support\Carbon::parse($verification['issued_at'])->format('d M Y, H:i') : '—' }}</dd></div>
    </dl>
    <p class="note">This page shows only a limited summary. It does not expose customer details or the full transaction record.</p>
</main>
</body>
</html>
