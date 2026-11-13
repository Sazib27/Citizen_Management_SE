<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Rent Agreement {{ $rentAgreement->agreement_number }}</title>
    <style>
        @page { margin: 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; line-height: 1.5; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: #6b7280; margin: 18px 0 6px; }
        .muted { color: #6b7280; }
        .header { border-bottom: 2px solid #059669; padding-bottom: 10px; margin-bottom: 14px; }
        .badge { display: inline-block; border: 1px solid #059669; color: #059669; padding: 2px 8px; border-radius: 999px; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 5px 0; vertical-align: top; }
        td.label { width: 32%; color: #6b7280; }
        .terms { border: 1px solid #e5e7eb; background: #f9fafb; padding: 12px; white-space: pre-line; }
        .signatures { margin-top: 34px; }
        .sig { width: 48%; }
        .sig-line { border-top: 1px solid #9ca3af; margin-top: 40px; padding-top: 4px; font-size: 10px; color: #6b7280; }
        .footer { margin-top: 26px; padding-top: 8px; border-top: 1px solid #e5e7eb; font-size: 9px; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Rental Agreement</h1>
        <p class="muted" style="margin:0;">
            Agreement No. <strong>{{ $rentAgreement->agreement_number }}</strong>
            &nbsp;·&nbsp; <span class="badge">{{ ucfirst($rentAgreement->status) }}</span>
        </p>
    </div>

    <h2>Parties</h2>
    <table>
        <tr>
            <td class="label">Landlord</td>
            <td>
                <strong>{{ $rentAgreement->landlord?->display_name ?? 'Unknown' }}</strong><br>
                <span class="muted">{{ $rentAgreement->landlord?->email }}</span>
            </td>
        </tr>
        <tr>
            <td class="label">Tenant</td>
            <td>
                <strong>{{ $rentAgreement->tenant?->display_name ?? 'Unknown' }}</strong><br>
                <span class="muted">{{ $rentAgreement->tenant?->email }}</span>
            </td>
        </tr>
    </table>

    <h2>Property</h2>
    <table>
        <tr>
            <td class="label">Title</td>
            <td>{{ $rentAgreement->property?->title ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Address</td>
            <td>
                {{ $rentAgreement->property?->address_line }},
                {{ $rentAgreement->property?->city }}
                @if($rentAgreement->property?->state), {{ $rentAgreement->property->state }} @endif
            </td>
        </tr>
        <tr>
            <td class="label">Area</td>
            <td>{{ $rentAgreement->property?->area_sqft }} sq ft</td>
        </tr>
    </table>

    <h2>Terms</h2>
    <table>
        <tr>
            <td class="label">Commencement date</td>
            <td>{{ $rentAgreement->start_date?->format('F d, Y') }}</td>
        </tr>
        <tr>
            <td class="label">Termination date</td>
            <td>{{ $rentAgreement->end_date?->format('F d, Y') }}</td>
        </tr>
        <tr>
            <td class="label">Monthly rent</td>
            <td>BDT {{ number_format((float) $rentAgreement->monthly_rent, 2) }}</td>
        </tr>
        <tr>
            <td class="label">Security deposit</td>
            <td>BDT {{ number_format((float) $rentAgreement->security_deposit, 2) }}</td>
        </tr>
    </table>

    <h2>Conditions</h2>
    <div class="terms">{{ $rentAgreement->terms_text }}</div>

    <div class="signatures">
        <table>
            <tr>
                <td class="sig">
                    <div class="sig-line">Landlord signature &amp; date</div>
                </td>
                <td class="sig">
                    <div class="sig-line">Tenant signature &amp; date</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="footer">
        Generated {{ $rentAgreement->generated_at?->format('F d, Y \a\t h:i A') ?? $rentAgreement->created_at->format('F d, Y') }}
        @if($rentAgreement->approver)
            · Approved by {{ $rentAgreement->approver->display_name }}
        @endif
        · Issued by the Citizen Services Portal
    </div>
</body>
</html>
