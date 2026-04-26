<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nota de Crédito {{ $cai['credit_note_number'] }} — {{ $company['name'] }}</title>

    {{-- CSS inline: la Blade es auto-contenida para que "Guardar como PDF" del navegador
         (desktop) y el share sheet nativo de iOS/Android generen un PDF vectorial fiel.
         Mismo esquema visual que la factura por consistencia de marca + familiaridad
         para el cliente, con colores acentuados para distinguir el tipo de documento. --}}
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #111;
            background: #f5f5f5;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* Wrapper imprimible — Letter 8.5in con margen */
        .page {
            max-width: 8.5in;
            margin: 20px auto;
            background: white;
            padding: 0.5in;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }

        /* Header: emisor (izq) + datos del documento/CAI (der) */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #b7791f;
            padding-bottom: 16px;
            margin-bottom: 16px;
            gap: 24px;
        }
        .company { flex: 1; min-width: 0; }
        .company h1 { font-size: 18px; font-weight: bold; margin-bottom: 4px; }
        .company p { font-size: 11px; margin: 2px 0; }

        .doc-info { text-align: right; flex: 0 0 260px; }
        .doc-info h2 {
            font-size: 18px;
            letter-spacing: 2px;
            margin-bottom: 8px;
            color: #b7791f; /* dorado: identifica a la NC visualmente */
        }
        .doc-info .label { font-size: 10px; font-weight: bold; text-transform: uppercase; color: #666; }
        .doc-info .value { font-size: 13px; font-weight: bold; }
        .doc-info .cai-number { font-size: 10px; font-family: 'Courier New', monospace; word-break: break-all; }
        .doc-info .cai-meta { margin-top: 4px; font-size: 10px; color: #444; }

        /* Cliente */
        .customer {
            display: flex;
            gap: 24px;
            margin: 16px 0;
            font-size: 12px;
            padding: 10px 12px;
            background: #fafafa;
            border-left: 3px solid #b7791f;
        }
        .customer .field { flex: 1; }
        .customer .label {
            font-size: 10px;
            font-weight: bold;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 2px;
        }

        /* Referencia a factura origen (exclusivo de NC) */
        .original-invoice {
            display: flex;
            gap: 24px;
            margin: 12px 0;
            padding: 10px 12px;
            background: #fef9e7;
            border-left: 3px solid #b7791f;
            font-size: 12px;
        }
        .original-invoice .field { flex: 1; }
        .original-invoice .heading {
            font-size: 10px;
            font-weight: bold;
            color: #7d5a14;
            text-transform: uppercase;
            margin-bottom: 6px;
            letter-spacing: 1px;
            flex: 0 0 100%;
        }
        .original-invoice .label {
            font-size: 10px;
            font-weight: bold;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 2px;
        }

        /* Razón de la NC (exclusivo de NC) */
        .reason-block {
            margin: 12px 0;
            padding: 10px 12px;
            background: #f8f9fa;
            border-left: 3px solid #444;
            font-size: 12px;
        }
        .reason-block .heading {
            font-size: 10px;
            font-weight: bold;
            color: #444;
            text-transform: uppercase;
            margin-bottom: 4px;
            letter-spacing: 1px;
        }
        .reason-block .label-value {
            font-weight: bold;
            color: #111;
            margin-bottom: 6px;
        }
        .reason-block .notes {
            color: #444;
            font-size: 11px;
            line-height: 1.5;
        }

        /* Tabla de items */
        table.items { width: 100%; border-collapse: collapse; margin: 16px 0; }
        table.items thead { background: #b7791f; color: white; }
        table.items th {
            padding: 8px 10px;
            font-size: 11px;
            text-transform: uppercase;
            text-align: left;
            font-weight: bold;
        }
        table.items th.num { text-align: right; }
        table.items td {
            padding: 8px 10px;
            font-size: 12px;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }
        table.items td.num {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }
        table.items td .sku { font-size: 10px; color: #666; display: block; margin-top: 2px; }
        table.items tr { page-break-inside: avoid; }

        /* Zona inferior: QR a la izquierda, totales a la derecha */
        .bottom-block {
            display: flex;
            justify-content: space-between;
            gap: 24px;
            margin-top: 20px;
            page-break-inside: avoid;
        }
        .qr-block {
            flex: 0 0 180px;
            text-align: center;
            font-size: 9px;
            color: #555;
        }
        .qr-block svg { display: block; margin: 0 auto 6px auto; }
        .qr-block .qr-label { font-size: 10px; font-weight: bold; color: #111; margin-bottom: 4px; }
        .qr-block .verify-url { word-break: break-all; font-size: 8px; line-height: 1.3; }

        .totals { flex: 0 0 280px; }
        .totals .row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            font-size: 12px;
        }
        .totals .row.total {
            border-top: 2px solid #b7791f;
            font-weight: bold;
            font-size: 14px;
            padding-top: 8px;
            margin-top: 6px;
            color: #7d5a14;
        }
        .totals .label { color: #555; }
        .totals .value { font-variant-numeric: tabular-nums; }

        /* Pie */
        .footer {
            margin-top: 24px;
            padding-top: 12px;
            border-top: 1px solid #ccc;
            font-size: 10px;
            text-align: center;
            color: #666;
        }
        .footer .legend {
            font-weight: bold;
            color: #111;
            margin-bottom: 6px;
            font-size: 11px;
        }
        .footer .sar-meta { margin-top: 6px; line-height: 1.5; }

        /* Banner de NC anulada */
        .void-banner {
            background: #c0392b;
            color: white;
            text-align: center;
            padding: 10px;
            font-weight: bold;
            letter-spacing: 3px;
            margin-bottom: 16px;
            font-size: 14px;
        }

        /* Acciones visibles solo en pantalla (NO en print ni en share as PDF) */
        .screen-actions {
            max-width: 8.5in;
            margin: 12px auto;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
        .screen-actions button {
            padding: 10px 24px;
            font-size: 14px;
            background: #111;
            color: white;
            border: 0;
            cursor: pointer;
            border-radius: 4px;
            font-family: inherit;
            font-weight: 600;
        }
        .screen-actions button:hover { background: #333; }

        /* REGLAS DE IMPRESION:
           En desktop esto aplica al dialogo "Imprimir".
           En mobile (iOS Safari / Chrome Android) al seleccionar "Imprimir"
           el sistema operativo genera un PDF vectorial que luego se puede
           compartir via share sheet (WhatsApp, email, etc). */
        @media print {
            @page { size: Letter; margin: 0.5in; }
            html, body { background: white; }
            .screen-actions { display: none !important; }
            .page {
                box-shadow: none;
                margin: 0;
                padding: 0;
                max-width: 100%;
            }
        }

        /* Mobile friendly */
        @media screen and (max-width: 640px) {
            .page { padding: 16px; margin: 8px; }
            .header { flex-direction: column; gap: 12px; }
            .doc-info { text-align: left; flex: 1; }
            .original-invoice { flex-direction: column; gap: 8px; }
            .bottom-block { flex-direction: column; }
            .qr-block { flex: 1; }
            .totals { flex: 1; }
        }
    </style>
</head>
<body>
    <div class="screen-actions">
        <button type="button" onclick="window.print()">Imprimir / Guardar como PDF</button>
    </div>

    <div class="page">
        @if ($isVoid)
            <div class="void-banner">NOTA DE CRÉDITO ANULADA</div>
        @endif

        {{-- ================= HEADER ================= --}}
        <div class="header">
            <div class="company">
                <h1>{{ $company['name'] }}</h1>
                @if (!empty($company['rtn']))
                    <p><strong>RTN:</strong> {{ $company['rtn'] }}</p>
                @endif
                @if (!empty($company['address']))
                    <p>{{ $company['address'] }}</p>
                @endif
                @if (!empty($company['phone']))
                    <p><strong>Tel:</strong> {{ $company['phone'] }}</p>
                @endif
                @if (!empty($company['email']))
                    <p>{{ $company['email'] }}</p>
                @endif
            </div>

            <div class="doc-info">
                <h2>{{ $cai['without_cai'] ? 'NOTA DE CRÉDITO (Sin CAI)' : 'NOTA DE CRÉDITO' }}</h2>

                <div style="margin: 6px 0">
                    <span class="label">No.</span>
                    <span class="value">{{ $cai['credit_note_number'] }}</span>
                </div>

                @if (!$cai['without_cai'])
                    <div style="margin: 8px 0">
                        <span class="label">CAI:</span>
                        <div class="cai-number">{{ $cai['number'] }}</div>
                    </div>

                    @if ($cai['range_from'] && $cai['range_to'])
                        <div class="cai-meta">
                            Rango autorizado:<br>
                            {{ $cai['range_from'] }} al {{ $cai['range_to'] }}
                        </div>
                    @endif

                    @if ($cai['expiration_date'])
                        <div class="cai-meta"><strong>Vence:</strong> {{ $cai['expiration_date'] }}</div>
                    @endif
                @endif

                @if (!empty($cai['emission_point']))
                    <div class="cai-meta">Punto de emisión: {{ $cai['emission_point'] }}</div>
                @endif
            </div>
        </div>

        {{-- ================= CLIENTE ================= --}}
        <div class="customer">
            <div class="field">
                <div class="label">Cliente</div>
                <div>{{ $customer['name'] }}</div>
            </div>
            @if (!empty($customer['rtn']))
                <div class="field">
                    <div class="label">RTN</div>
                    <div>{{ $customer['rtn'] }}</div>
                </div>
            @endif
            <div class="field">
                <div class="label">Fecha NC</div>
                <div>{{ $creditNote->credit_note_date?->format('d/m/Y') }}</div>
            </div>
        </div>

        {{-- ================= FACTURA ORIGEN ================= --}}
        <div class="original-invoice">
            <div class="heading">Factura que se acredita</div>
            <div class="field">
                <div class="label">No. Factura</div>
                <div>{{ $originalInvoice['number'] }}</div>
            </div>
            @if (!empty($originalInvoice['cai']))
                <div class="field">
                    <div class="label">CAI Factura</div>
                    <div style="font-family: 'Courier New', monospace; font-size: 10px; word-break: break-all;">
                        {{ $originalInvoice['cai'] }}
                    </div>
                </div>
            @endif
            @if ($originalInvoice['date'])
                <div class="field">
                    <div class="label">Fecha Factura</div>
                    <div>{{ $originalInvoice['date'] }}</div>
                </div>
            @endif
        </div>

        {{-- ================= RAZÓN ================= --}}
        <div class="reason-block">
            <div class="heading">Razón de emisión</div>
            <div class="label-value">{{ $reason['label'] }}</div>
            @if (!empty($reason['notes']))
                <div class="notes">{{ $reason['notes'] }}</div>
            @endif
        </div>

        {{-- ================= ITEMS ================= --}}
        <table class="items">
            <thead>
                <tr>
                    <th>Descripción</th>
                    <th class="num">Cant.</th>
                    <th class="num">Precio Unit.</th>
                    <th class="num">Total</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    <tr>
                        <td>
                            {{ $item['description'] }}
                            @if (!empty($item['sku']))
                                <span class="sku">SKU: {{ $item['sku'] }}</span>
                            @endif
                        </td>
                        <td class="num">{{ $item['quantity'] }}</td>
                        <td class="num">L {{ $item['unit_price'] }}</td>
                        <td class="num">L {{ $item['line_total'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" style="text-align: center; color: #888; padding: 20px;">
                            Sin ítems en esta nota de crédito.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        {{-- ================= QR + TOTALES ================= --}}
        <div class="bottom-block">
            <div class="qr-block">
                {!! $qrSvg !!}
                <div class="qr-label">Verificar nota de crédito</div>
                <div class="verify-url">{{ $verifyUrl }}</div>
            </div>

            <div class="totals">
                @if ($totals['has_exempt'])
                    <div class="row">
                        <span class="label">Exento:</span>
                        <span class="value">L {{ $totals['exempt'] }}</span>
                    </div>
                @endif
                <div class="row">
                    <span class="label">Subtotal gravado:</span>
                    <span class="value">L {{ $totals['taxable'] }}</span>
                </div>
                <div class="row">
                    <span class="label">ISV 15%:</span>
                    <span class="value">L {{ $totals['isv'] }}</span>
                </div>
                <div class="row total">
                    <span>TOTAL CRÉDITO:</span>
                    <span class="value">L {{ $totals['total'] }}</span>
                </div>
            </div>
        </div>

        {{-- ================= FOOTER ================= --}}
        <div class="footer">
            <div class="legend">{{ $footerLegend }}</div>
            <div class="sar-meta">
                Software: <strong>{{ $software['name'] }}</strong> v{{ $software['version'] }}
                · {{ $software['developer'] }}
                · Estructura: {{ ucfirst($software['structure']) }}
            </div>
        </div>
    </div>
</body>
</html>
