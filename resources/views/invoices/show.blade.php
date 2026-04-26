<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Factura {{ $invoice->invoice_number }}</title>
    <style>
        /* ═══ Reset ═══ */
        *, *::before, *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            font-size: 14px;
            color: #1a1a1a;
            background: #f1f5f9;
            line-height: 1.5;
        }

        /* ═══ Toolbar (no se imprime) ═══ */
        .toolbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 100;
            background: #1e293b;
            padding: 12px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }

        .toolbar-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .toolbar-left a {
            color: #94a3b8;
            text-decoration: none;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: color 0.15s;
        }

        .toolbar-left a:hover {
            color: #ffffff;
        }

        .toolbar-title {
            color: #ffffff;
            font-weight: 600;
            font-size: 15px;
        }

        .btn-print {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 20px;
            background: #10b981;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
        }

        .btn-print:hover {
            background: #059669;
        }

        /* ═══ Contenedor de la factura ═══ */
        .invoice-wrapper {
            max-width: 816px;
            margin: 80px auto 40px;
            padding: 0 16px;
        }

        .invoice-page {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            padding: 40px;
            position: relative;
        }

        /* ═══ Header: Logo + Datos empresa + Número factura ═══ */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid #1e293b;
        }

        .company-section {
            flex: 1;
        }

        .company-name {
            font-size: 22px;
            font-weight: 800;
            color: #1e293b;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .company-activity {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            margin-bottom: 8px;
            max-width: 380px;
            line-height: 1.4;
        }

        .company-details {
            font-size: 12px;
            color: #475569;
            line-height: 1.6;
        }

        .invoice-badge {
            text-align: right;
            flex-shrink: 0;
        }

        .badge-title {
            display: inline-block;
            background: #1e293b;
            color: #ffffff;
            font-size: 18px;
            font-weight: 700;
            padding: 8px 24px;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 12px;
        }

        .invoice-meta {
            font-size: 12px;
            color: #475569;
            text-align: right;
            line-height: 1.8;
        }

        .invoice-meta strong {
            color: #1e293b;
        }

        /* ═══ Barra fiscal CAI ═══ */
        .cai-bar {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 10px 16px;
            margin-bottom: 20px;
            font-size: 11px;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 4px;
        }

        .cai-bar span {
            color: #64748b;
        }

        .cai-bar strong {
            color: #1e293b;
            font-family: 'Courier New', monospace;
            font-size: 12px;
        }

        /* ═══ Datos del cliente ═══ */
        .client-box {
            border: 1px solid #e2e8f0;
            padding: 14px 18px;
            margin-bottom: 20px;
        }

        .client-box p {
            font-size: 13px;
            color: #475569;
            margin-bottom: 2px;
        }

        .client-box strong {
            color: #1e293b;
        }

        /* ═══ Tabla de items ═══ */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .items-table thead th {
            background: #1e293b;
            color: #ffffff;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 10px 12px;
            text-align: left;
        }

        .items-table thead th.text-right {
            text-align: right;
        }

        .items-table thead th.text-center {
            text-align: center;
        }

        .items-table tbody td {
            padding: 10px 12px;
            font-size: 13px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
        }

        .items-table tbody td.text-right {
            text-align: right;
        }

        .items-table tbody td.text-center {
            text-align: center;
        }

        .items-table tbody tr:nth-child(even) {
            background: #f8fafc;
        }

        .item-sku {
            font-size: 10px;
            color: #94a3b8;
            font-family: 'Courier New', monospace;
        }

        /* ═══ Footer: Totales + Campos SAR ═══ */
        .footer-section {
            display: flex;
            justify-content: space-between;
            gap: 24px;
            margin-bottom: 20px;
        }

        .sar-fields {
            flex: 1;
            border: 1px solid #e2e8f0;
            padding: 14px 18px;
            font-size: 12px;
            color: #64748b;
            line-height: 2;
        }

        .sar-fields .field-line {
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 2px;
            margin-bottom: 4px;
        }

        .totals-box {
            width: 280px;
            flex-shrink: 0;
        }

        .totals-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            font-size: 13px;
            color: #475569;
        }

        .totals-row.discount {
            color: #dc2626;
        }

        .totals-row span:last-child {
            font-family: 'Courier New', monospace;
            font-weight: 600;
        }

        .total-divider {
            border-top: 2px solid #1e293b;
            margin-top: 8px;
            padding-top: 8px;
        }

        .total-final {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .total-final-label {
            font-size: 16px;
            font-weight: 800;
            color: #1e293b;
        }

        .total-final-value {
            font-size: 18px;
            font-weight: 800;
            color: #1e293b;
            font-family: 'Courier New', monospace;
        }

        /* ═══ Pie de página ═══ */
        .invoice-footer {
            text-align: center;
            font-size: 13px;
            font-weight: 700;
            color: #1e293b;
            padding-top: 16px;
            border-top: 1px solid #e2e8f0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .invoice-footer-sub {
            text-align: center;
            font-size: 10px;
            color: #94a3b8;
            margin-top: 6px;
        }

        /* ═══ Sin CAI ═══ */
        .sin-cai-badge {
            display: inline-block;
            background: #fef2f2;
            border: 1px solid #fca5a5;
            color: #991b1b;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 12px;
            text-transform: uppercase;
            margin-top: 8px;
        }

        /* ═══ Marca de agua ANULADA ═══ */
        .void-watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 72px;
            color: rgba(220, 38, 38, 0.12);
            font-weight: 900;
            letter-spacing: 12px;
            text-transform: uppercase;
            pointer-events: none;
            z-index: 10;
        }

        /* ═══════════════════════════════════════════════
           ESTILOS DE IMPRESIÓN
           ═══════════════════════════════════════════════ */
        @media print {
            body {
                background: white;
                font-size: 12px;
            }

            .toolbar {
                display: none !important;
            }

            .invoice-wrapper {
                margin: 0;
                padding: 0;
                max-width: none;
            }

            .invoice-page {
                border: none;
                box-shadow: none;
                padding: 20px;
                border-radius: 0;
            }

            .items-table thead th {
                background: #1e293b !important;
                color: #ffffff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .items-table tbody tr:nth-child(even) {
                background: #f8fafc !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .badge-title {
                background: #1e293b !important;
                color: #ffffff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .void-watermark {
                color: rgba(220, 38, 38, 0.15) !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            @page {
                size: letter;
                margin: 10mm;
            }
        }
    </style>
</head>
<body>

    {{-- ═══ TOOLBAR (no se imprime) ═══ --}}
    <div class="toolbar">
        <div class="toolbar-left">
            <a href="{{ url()->previous() }}">
                &larr; Volver
            </a>
            <span class="toolbar-title">Factura {{ $invoice->invoice_number }}</span>
        </div>
        <button class="btn-print" onclick="window.print()">
            &#128424; Imprimir Factura
        </button>
    </div>

    {{-- ═══ FACTURA ═══ --}}
    <div class="invoice-wrapper">
        <div class="invoice-page">

            @if($invoice->is_void)
                <div class="void-watermark">ANULADA</div>
            @endif

            {{-- Header --}}
            <div class="header">
                <div class="company-section">
                    <div class="company-name">{{ $invoice->company_name }}</div>
                    @if($company && $company->business_type)
                        <div class="company-activity">{{ $company->business_type }}</div>
                    @endif
                    <div class="company-details">
                        {{ $invoice->company_address }}<br>
                        @if($invoice->company_phone)
                            Tel: {{ $invoice->company_phone }}<br>
                        @endif
                        RTN: {{ $invoice->company_rtn }}
                        @if($invoice->company_email)
                            <br>Email: {{ $invoice->company_email }}
                        @endif
                    </div>
                </div>
                <div class="invoice-badge">
                    <div class="badge-title">FACTURA</div>
                    <div class="invoice-meta">
                        @if(!$invoice->without_cai && $invoice->cai)
                            <strong>CAI:</strong> {{ $invoice->cai }}<br>
                        @endif
                        <strong>No. Factura:</strong> {{ $invoice->invoice_number }}<br>
                        <strong>Fecha:</strong> {{ $invoice->invoice_date->format('d/m/Y') }}
                        @if($caiRange)
                            <br><strong>Rango autorizado:</strong>
                            {{ $caiRange->prefix }}-{{ str_pad($caiRange->range_start, 8, '0', STR_PAD_LEFT) }}
                            al
                            {{ $caiRange->prefix }}-{{ str_pad($caiRange->range_end, 8, '0', STR_PAD_LEFT) }}
                            <br><strong>Fecha limite de emisión:</strong>
                            {{ $invoice->cai_expiration_date?->format('d/m/Y') }}
                        @endif
                    </div>
                    @if($invoice->without_cai)
                        <div class="sin-cai-badge">DOCUMENTO SIN VALOR FISCAL</div>
                    @endif
                </div>
            </div>

            {{-- Datos del Cliente --}}
            <div class="client-box">
                <p><strong>Cliente:</strong> {{ $invoice->customer_name }}</p>
                <p><strong>RTN:</strong> {{ $invoice->customer_rtn ?: '---' }}</p>
                @if($sale->notes)
                    <p><strong>Notas:</strong> {{ $sale->notes }}</p>
                @endif
            </div>

            {{-- Tabla de Items --}}
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width: 12%;">Código</th>
                        <th style="width: 35%;">Producto</th>
                        <th class="text-right" style="width: 15%;">Precio</th>
                        <th class="text-center" style="width: 10%;">Cantidad</th>
                        <th class="text-center" style="width: 13%;">ISV</th>
                        <th class="text-right" style="width: 15%;">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $item)
                        @php
                            $taxTypeValue = $item->tax_type instanceof \App\Enums\TaxType
                                ? $item->tax_type->value
                                : $item->tax_type;
                            $isGravado = $taxTypeValue === 'gravado_15';
                            $lineTotal = $item->unit_price * $item->quantity;
                        @endphp
                        <tr>
                            <td>
                                <span class="item-sku">{{ $item->product?->sku ?? '--' }}</span>
                            </td>
                            <td>{{ $item->product?->name ?? 'Producto' }}</td>
                            <td class="text-right">L {{ number_format($item->unit_price, 2) }}</td>
                            <td class="text-center">{{ $item->quantity }}</td>
                            <td class="text-center">{{ $isGravado ? '15%' : 'Exento' }}</td>
                            <td class="text-right">L {{ number_format($lineTotal, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            {{-- Footer: Campos SAR + Totales --}}
            <div class="footer-section">
                {{-- Campos SAR obligatorios (lado izquierdo) --}}
                <div class="sar-fields">
                    <div class="field-line">Orden Compra Exenta: ___________________</div>
                    <div class="field-line">Constancia Reg. Exonerado: _______________</div>
                    <div class="field-line">Identificación Reg. SAG: _______________</div>
                </div>

                {{-- Totales (lado derecho) --}}
                <div class="totals-box">
                    <div class="totals-row">
                        <span>Subtotal:</span>
                        <span>L {{ number_format($invoice->subtotal, 2) }}</span>
                    </div>
                    <div class="totals-row">
                        <span>Importe Exento:</span>
                        <span>L {{ number_format($invoice->exempt_total, 2) }}</span>
                    </div>
                    <div class="totals-row">
                        <span>Importe Gravado 15%:</span>
                        <span>L {{ number_format($invoice->taxable_total, 2) }}</span>
                    </div>
                    <div class="totals-row">
                        <span>ISV 15%:</span>
                        <span>L {{ number_format($invoice->isv, 2) }}</span>
                    </div>
                    @if($invoice->discount > 0)
                        <div class="totals-row discount">
                            <span>Descuento:</span>
                            <span>-L {{ number_format($invoice->discount, 2) }}</span>
                        </div>
                    @endif
                    <div class="total-divider">
                        <div class="total-final">
                            <span class="total-final-label">TOTAL:</span>
                            <span class="total-final-value">L {{ number_format($invoice->total, 2) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Pie de factura --}}
            <div class="invoice-footer">
                @if($invoice->without_cai)
                    Este documento no tiene valor fiscal — comprobante interno de referencia
                @else
                    &iexcl;LA FACTURA ES BENEFICIO DE TODOS. EX&Iacute;JALA!
                @endif
            </div>
            <div class="invoice-footer-sub">
                Original: Cliente &nbsp;|&nbsp; Copia: Obligado Tributario
            </div>

        </div>
    </div>

</body>
</html>
