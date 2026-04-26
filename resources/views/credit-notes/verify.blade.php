<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verificación de Nota de Crédito {{ $cai['credit_note_number'] }} — {{ $company['name'] }}</title>

    {{-- CSS inline: autocontenida, consistente visualmente con print.blade.php (paleta dorada
         #b7791f para identificar NC). Diferencias con print:
         1. Banner superior VÁLIDA (verde) / ANULADA (rojo) — comunica estado al escaneador.
         2. Watermark diagonal "VERIFICACIÓN PÚBLICA" — evita que se confunda con el original.
         3. Nota declarativa SAR arriba — explica el propósito legal de esta vista.
         4. Sin QR (ya estamos en la vista del QR) — en su lugar, hash de integridad al pie. --}}
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #111;
            background: #f5f5f5;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            position: relative;
        }

        /* ============ BANNER SUPERIOR DE ESTADO ============ */
        .status-banner {
            width: 100%;
            padding: 14px 20px;
            text-align: center;
            font-weight: bold;
            letter-spacing: 4px;
            font-size: 16px;
            color: white;
            text-transform: uppercase;
        }
        .status-banner.valid  { background: #27ae60; }
        .status-banner.void   { background: #c0392b; }
        .status-banner .sub {
            display: block;
            font-size: 11px;
            font-weight: 400;
            letter-spacing: 0.5px;
            margin-top: 4px;
            opacity: 0.95;
        }

        /* ============ WATERMARK DIAGONAL ============ */
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-32deg);
            font-size: 90px;
            font-weight: 900;
            color: rgba(183, 121, 31, 0.10); /* dorado translúcido, consistente con tema NC */
            pointer-events: none;
            z-index: 0;
            white-space: nowrap;
            letter-spacing: 8px;
            user-select: none;
        }

        /* ============ WRAPPER IMPRIMIBLE ============ */
        .page {
            max-width: 8.5in;
            margin: 20px auto;
            background: white;
            padding: 0.5in;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            position: relative;
            z-index: 1;
        }

        /* ============ HEADER ============ */
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
            color: #b7791f;
        }
        .doc-info .label { font-size: 10px; font-weight: bold; text-transform: uppercase; color: #666; }
        .doc-info .value { font-size: 13px; font-weight: bold; }
        .doc-info .cai-number { font-size: 10px; font-family: 'Courier New', monospace; word-break: break-all; }
        .doc-info .cai-meta { margin-top: 4px; font-size: 10px; color: #444; }

        /* ============ NOTA DECLARATIVA SAR ============ */
        .verification-notice {
            background: #fef9e7;
            border-left: 4px solid #b7791f;
            padding: 10px 14px;
            font-size: 11px;
            line-height: 1.5;
            color: #7d5a14;
            margin-bottom: 16px;
        }
        .verification-notice strong { color: #5a4110; }

        /* ============ CLIENTE ============ */
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

        /* ============ FACTURA ORIGEN (exclusivo de NC) ============ */
        .original-invoice {
            display: flex;
            flex-wrap: wrap;
            gap: 24px;
            margin: 12px 0;
            padding: 10px 12px;
            background: #fef9e7;
            border-left: 3px solid #b7791f;
            font-size: 12px;
        }
        .original-invoice .field { flex: 1; min-width: 140px; }
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

        /* ============ RAZÓN (exclusivo de NC) ============ */
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

        /* ============ TABLA DE ITEMS ============ */
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

        /* ============ TOTALES (sin QR porque ya estamos en la vista del QR) ============ */
        .totals-block {
            display: flex;
            justify-content: flex-end;
            margin-top: 20px;
        }
        .totals { flex: 0 0 320px; }
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

        /* ============ HASH DE VERIFICACIÓN ============ */
        .integrity-box {
            margin-top: 20px;
            padding: 10px 14px;
            background: #f8f8f8;
            border: 1px dashed #aaa;
            font-size: 10px;
            font-family: 'Courier New', monospace;
            color: #555;
            word-break: break-all;
            line-height: 1.5;
        }
        .integrity-box .label {
            font-family: 'Helvetica Neue', Helvetica, sans-serif;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 10px;
            color: #111;
            display: block;
            margin-bottom: 4px;
            letter-spacing: 1px;
        }

        /* ============ FOOTER ============ */
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

        /* Acciones visibles solo en pantalla */
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

        /* REGLAS DE IMPRESION */
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
            .watermark {
                position: absolute;
                color: rgba(183, 121, 31, 0.15);
            }
        }

        /* Mobile friendly */
        @media screen and (max-width: 640px) {
            .page { padding: 16px; margin: 8px; }
            .header { flex-direction: column; gap: 12px; }
            .doc-info { text-align: left; flex: 1; }
            .original-invoice { flex-direction: column; gap: 8px; }
            .totals-block { justify-content: stretch; }
            .totals { flex: 1; }
            .watermark { font-size: 50px; letter-spacing: 4px; }
            .status-banner { font-size: 14px; letter-spacing: 2px; }
        }
    </style>
</head>
<body>
    {{-- ================= BANNER DE ESTADO ================= --}}
    @if ($isVoid)
        <div class="status-banner void">
            Nota de Crédito Anulada
            <span class="sub">Este documento fue anulado y no tiene validez fiscal</span>
        </div>
    @else
        <div class="status-banner valid">
            Nota de Crédito Válida
            <span class="sub">Documento fiscal autenticado por verificación SAR</span>
        </div>
    @endif

    {{-- ================= WATERMARK ================= --}}
    <div class="watermark">VERIFICACIÓN PÚBLICA</div>

    <div class="screen-actions">
        <button type="button" onclick="window.print()">Imprimir / Guardar como PDF</button>
    </div>

    <div class="page">
        {{-- ================= NOTA DECLARATIVA ================= --}}
        <div class="verification-notice">
            <strong>Verificación Pública de Documento Fiscal.</strong>
            Esta página muestra la representación fiel de una nota de crédito emitida bajo el
            régimen de autoimpresión SAR (Acuerdo 481-2017). Los datos provienen del emisor y
            están protegidos por un hash de integridad SHA-256. Esta vista NO reemplaza al
            comprobante fiscal impreso, es una verificación de autenticidad.
        </div>

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

        {{-- ================= FACTURA ORIGEN =================
             Se muestran los datos de la factura origen tal como vienen en el snapshot
             de la NC (original_invoice_*). La NC es autocontenida: NO se consulta ni se
             re-verifica el estado actual de la factura origen — el documento acredita
             sobre los datos que quedaron sellados al momento de emitir la NC. --}}
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

        {{-- ================= TOTALES ================= --}}
        <div class="totals-block">
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

        {{-- ================= HASH DE INTEGRIDAD ================= --}}
        <div class="integrity-box">
            <span class="label">Hash de Integridad (SHA-256)</span>
            {{ $creditNote->integrity_hash }}
        </div>

        {{-- ================= FOOTER ================= --}}
        <div class="footer">
            <div class="legend">{{ $footerLegend }}</div>
            <div class="sar-meta">
                Software: <strong>{{ $software['name'] }}</strong> v{{ $software['version'] }}
                · {{ $software['developer'] }}
                · Estructura: {{ ucfirst($software['structure']) }}
            </div>
            <div class="sar-meta" style="margin-top: 8px; color: #888;">
                Verificación consultada el {{ now()->format('d/m/Y H:i') }}
            </div>
        </div>
    </div>
</body>
</html>
