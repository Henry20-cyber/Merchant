
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Receipt {{ $receipt->receipt_number }}</title>

    <style>
        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            background: #fff;
            color: #111;
            font-family: Arial, Helvetica, sans-serif;
        }

        .receipt {
            width: 100%;
            max-width: 100%;
            margin: 0;
            padding: 3mm;
            overflow-wrap: break-word;
            word-wrap: break-word;
        }

        .receipt-58mm {
    width: 100%;
    max-width: 100%;
    font-size: 9px;
}

.receipt-80mm {
    width: 100%;
    max-width: 100%;
    font-size: 10px;
}

.receipt-a4 {
    width: 100%;
    max-width: 100%;
    padding: 12mm;
}

        .header {
            text-align: center;
            margin-bottom: 16px;
        }

        .business-name {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .receipt-title {
            font-size: 16px;
            font-weight: 700;
            margin-top: 10px;
        }

        .meta {
            margin: 12px 0;
            font-size: 13px;
        }

        .meta-row {
            display: table;
            width: 100%;
            table-layout: fixed;
            margin-bottom: 4px;
        }

        .meta-row > span,
        .meta-row > strong {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            overflow-wrap: break-word;
            word-wrap: break-word;
        }

        .meta-row > span:last-child,
        .meta-row > strong:last-child {
            text-align: right;
        }

        .divider {
            border-top: 1px dashed #555;
            margin: 12px 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: inherit;
        }

        th,
        td {
            padding: 4px 2px;
            text-align: left;
            vertical-align: top;
            overflow-wrap: break-word;
            word-wrap: break-word;
        }

        th:first-child,
td:first-child {
    width: 48%;
}

th:nth-child(2),
td:nth-child(2) {
    width: 17%;
}

th:nth-child(3),
td:nth-child(3) {
    width: 35%;
}

        th {
            border-bottom: 1px solid #222;
        }

        .text-right {
            text-align: right;
        }

        .totals {
            margin-top: 12px;
            font-size: 14px;
        }

        .total-row {
            display: table;
            width: 100%;
            table-layout: fixed;
            margin-bottom: 5px;
        }

        .total-row > span {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            overflow-wrap: break-word;
            word-wrap: break-word;
        }

        .total-row > span:last-child {
            text-align: right;
        }

        .grand-total {
            font-size: 17px;
            font-weight: 700;
            border-top: 1px solid #222;
            padding-top: 8px;
            margin-top: 8px;
            page-break-inside: avoid;
        }

        .verification-qr {
            text-align: center;
            margin: 16px auto 8px;
            page-break-inside: avoid;
        }

        .verification-qr img {
            display: block;
            width: 30mm;
            height: 30mm;
            margin: 0 auto;
        }

        .footer {
            text-align: center;
            margin-top: 20px;
            font-size: 12px;
            page-break-inside: avoid;
        }

        @media print {
            body {
                background: #fff;
            }

            .receipt {
                padding: 0;
                margin: 0;
            }

            .no-print {
                display: none !important;
            }
        }
    </style>
</head>

<body>

@php
    /*
    |--------------------------------------------------------------------------
    | Receipt Snapshot
    |--------------------------------------------------------------------------
    |
    | Historical receipt information is read from the saved snapshot.
    | Do not replace it with live sale, customer, or business relations.
    |
    */

    $snapshot = $snapshot ?? $receipt->snapshot ?? [];

    $business = data_get($snapshot, 'business', []);
    $receiptData = data_get($snapshot, 'receipt', []);
    $sale = data_get($snapshot, 'sale', []);
    $customer = data_get($snapshot, 'customer');
    $cashier = data_get($snapshot, 'cashier');
    $items = data_get($snapshot, 'items', []);
    $payments = data_get($snapshot, 'payments', []);

    $format = $format ?? '80mm';

    $formatClass = match ($format) {
        '58mm' => 'receipt-58mm',
        '80mm' => 'receipt-80mm',
        'a4' => 'receipt-a4',
        default => 'receipt-80mm',
    };

    $currency = $business['currency'] ?? 'NGN';

    $formatMoney = function ($amount) {
        return number_format((float) $amount, 2);
    };
@endphp

<div class="receipt {{ $formatClass }}" id="receipt-{{ $format }}">

    {{-- HEADER --}}

    <div class="header">
        <div class="business-name">
            {{ $business['name'] ?? 'Account' }}
        </div>

        @if(!empty($business['address']))
            <div>{{ $business['address'] }}</div>
        @endif

        @php
            $businessLocation = array_filter([
                $business['city'] ?? null,
                $business['state'] ?? null,
                $business['country'] ?? null,
            ]);
        @endphp

        @if(!empty($business['email']))
            <div>{{ $business['email'] }}</div>
        @endif

        @if(!empty($business['phone']))
            <div>{{ $business['phone'] }}</div>
        @endif

        @if(!empty($business['website']))
            <div>{{ $business['website'] }}</div>
        @endif

        @if(count($businessLocation) > 0)
            <div>{{ implode(', ', $businessLocation) }}</div>
        @endif

        <div class="receipt-title">RECEIPT</div>
    </div>

    {{-- RECEIPT METADATA --}}

    <div class="meta">
        <div class="meta-row">
            <span>Receipt #</span>
            <strong>
                {{ $receiptData['number'] ?? $receipt->receipt_number }}
            </strong>
        </div>

        @if(!empty($receiptData['issued_at']))
            <div class="meta-row">
                <span>Date</span>
                <span>
                    {{ \Carbon\Carbon::parse($receiptData['issued_at'])->format('Y-m-d H:i') }}
                </span>
            </div>
        @elseif($receipt->issued_at)
            <div class="meta-row">
                <span>Date</span>
                <span>{{ $receipt->issued_at->format('Y-m-d H:i') }}</span>
            </div>
        @endif

        @if($cashier)
            <div class="meta-row">
                <span>Cashier</span>
                <span>{{ $cashier['name'] ?? '—' }}</span>
            </div>
        @endif
    </div>

    <div class="divider"></div>

    {{-- CUSTOMER --}}

    @if($customer)
        <div class="meta">
            <div class="meta-row">
                <span>Customer</span>
                <span>{{ $customer['name'] ?? '—' }}</span>
            </div>

            @if(!empty($customer['customer_number']))
                <div class="meta-row">
                    <span>Customer #</span>
                    <span>{{ $customer['customer_number'] }}</span>
                </div>
            @endif

            @if(!empty($customer['phone']))
                <div class="meta-row">
                    <span>Phone</span>
                    <span>{{ $customer['phone'] }}</span>
                </div>
            @endif
        </div>
    @endif

    {{-- SALE INFORMATION --}}

    @if(!empty($sale['id']))
        <div class="meta">
            <div class="meta-row">
                <span>Sale</span>
                <span>{{ $sale['id'] }}</span>
            </div>

            @if(!empty($sale['payment_method']))
                <div class="meta-row">
                    <span>Payment</span>
                    <span>
                        {{ ucfirst(str_replace('_', ' ', $sale['payment_method'])) }}
                    </span>
                </div>
            @endif
        </div>
    @endif

    <div class="divider"></div>

    {{-- LINE ITEMS --}}

    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Amount</th>
            </tr>
        </thead>

        <tbody>
            @forelse($items as $item)
                <tr>
                    <td>
                        {{ $item['name'] ?? 'Item' }}

                        @if(($item['type'] ?? null) === 'service')
                            <div style="font-size: 11px;">Service</div>
                        @endif
                    </td>

                    <td class="text-right">
                        {{ $item['quantity'] ?? '0' }}
                    </td>

                    <td class="text-right">
                        {{ $formatMoney($item['total'] ?? 0) }}
                    </td>
                </tr>

                @if(isset($item['discount']) && (float) $item['discount'] > 0)
                    <tr>
                        <td colspan="2">Discount</td>
                        <td class="text-right">
                            -{{ $formatMoney($item['discount']) }}
                        </td>
                    </tr>
                @endif
            @empty
                <tr>
                    <td colspan="3" class="text-right">No line items</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="divider"></div>

    {{-- TOTALS --}}

    <div class="totals">
        <div class="total-row">
            <span>Subtotal</span>
            <span>
                {{ $currency }}
                {{ $formatMoney($sale['subtotal'] ?? 0) }}
            </span>
        </div>

        @if(isset($sale['discount']) && (float) $sale['discount'] > 0)
            <div class="total-row">
                <span>Discount</span>
                <span>
                    -{{ $currency }}
                    {{ $formatMoney($sale['discount']) }}
                </span>
            </div>
        @endif

        @if(isset($sale['vat_amount']) && (float) $sale['vat_amount'] > 0)
            <div class="total-row">
                <span>
                    VAT
                    @if(isset($sale['vat_rate']))
                        ({{ $formatMoney($sale['vat_rate']) }}%)
                    @endif
                </span>
                <span>
                    {{ $currency }}
                    {{ $formatMoney($sale['vat_amount']) }}
                </span>
            </div>
        @endif

        <div class="total-row grand-total">
            <span>Total</span>
            <span>
                {{ $currency }}
                {{ $formatMoney($sale['total'] ?? 0) }}
            </span>
        </div>
    </div>

    {{-- PAYMENT DETAILS --}}

    @if(count($payments) > 0)
        <div class="divider"></div>

        <div class="meta">
            <strong>Payment Details</strong>

            @foreach($payments as $payment)
                <div class="meta-row">
                    <span>
                        {{ ucfirst(str_replace('_', ' ', $payment['method'] ?? 'Payment')) }}
                    </span>
                    <span>
                        {{ $currency }}
                        {{ $formatMoney($payment['amount'] ?? 0) }}
                    </span>
                </div>

                @if(!empty($payment['reference']))
                    <div class="meta-row">
                        <span>Reference</span>
                        <span>{{ $payment['reference'] }}</span>
                    </div>
                @endif
            @endforeach
        </div>
    @endif

    {{-- VERIFICATION QR CODE --}}

    @if(!empty($verificationQrSvg))
        <div class="verification-qr">
            <img
                src="data:image/svg+xml;base64,{{ base64_encode($verificationQrSvg) }}"
                alt="Receipt verification QR code"
            >
            <div style="font-size:10px; margin-top:4px;">
                Scan to verify receipt authenticity
            </div>
        </div>
    @endif

    {{-- FOOTER --}}

    <div class="footer">
        <div>Thanks for patronising us!</div>
        <div>Powered by Account</div>
    </div>

</div>

</body>
</html>
