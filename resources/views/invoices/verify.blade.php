<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verificación de Factura {{ $cai['invoice_number'] }} — {{ $company['name'] }}</title>

    {{--
        Verificación pública — alineada al mismo sistema visual de print.blade.php
        para que el contribuyente vea EXACTAMENTE la misma factura que imprimió
        el emisor, solo que con 3 capas adicionales que distinguen esta vista:

          1. Banner superior VÁLIDA (verde) / ANULADA (rojo) — estado fiscal
             leído en vivo desde la BD (no snapshot).
          2. Watermark diagonal "VERIFICACIÓN PÚBLICA" — previene que la vista
             se confunda con el original fiscal imprimible.
          3. Aviso SAR superior — declara el régimen legal (Acuerdo 481-2017) y
             que esta pantalla es una representación fiel protegida por hash.

        Se OMITEN 3 bloques del print porque no aplican:
          - SAR fields de llenado manual (O. C. Exenta / Reg. Exonerado / SAG):
            solo tienen sentido en papel físico rellenado a mano.
          - QR: el usuario ya está en la URL del QR, redundante.
          - Firmas (Entregado por): la verificación digital no se firma.

        Se CONSERVA el hash SHA-256 de integridad como prueba de no-repudio
        (mismo que se calcula al emitir; si cambia, la factura fue alterada).

        CSS autocontenido — compatible con hosting sin Node/Vite.
    --}}
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html, body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #1a1a1a;
            background: #eef1f5;
            line-height: 1.4;
            font-size: 11px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            position: relative;
        }

        /* ═══ Banner estado (VÁLIDA / ANULADA) ═══ */
        .status-banner {
            width: 100%;
            padding: 12px 20px;
            text-align: center;
            font-weight: 800;
            letter-spacing: 4px;
            font-size: 15px;
            color: white;
            text-transform: uppercase;
        }
        .status-banner.valid { background: #16a34a; }
        .status-banner.void  { background: #dc2626; }
        .status-banner .sub {
            display: block;
            font-size: 10px;
            font-weight: 500;
            letter-spacing: 0.5px;
            margin-top: 3px;
            opacity: 0.95;
            text-transform: none;
        }

        /* ═══ Watermark diagonal de verificación ═══ */
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-28deg);
            font-size: 85px;
            font-weight: 900;
            color: rgba(15, 23, 42, 0.07);
            pointer-events: none;
            z-index: 0;
            white-space: nowrap;
            letter-spacing: 10px;
            user-select: none;
            text-transform: uppercase;
        }

        /* ═══ Página ═══ */
        .page {
            max-width: 8.5in;
            margin: 0 auto 30px;
            background: white;
            padding: 0.35in;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.1);
            position: relative;
            z-index: 1;
        }

        /* ═══ Aviso declarativo SAR ═══ */
        .verification-notice {
            background: #eff6ff;
            border-left: 4px solid #2563eb;
            padding: 9px 13px;
            font-size: 10px;
            line-height: 1.5;
            color: #1e3a8a;
            margin-bottom: 12px;
            border-radius: 2px;
        }
        .verification-notice strong { color: #0c1e4a; }

        /* ═══ Header (mismo patrón que print) ═══ */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #0f172a;
            margin-bottom: 10px;
        }
        .emitter { flex: 1; min-width: 0; display: flex; gap: 12px; }
        .emitter .logo {
            flex: 0 0 auto;
            width: 56px;
            height: 56px;
            object-fit: contain;
            display: block;
        }
        .emitter .logo-placeholder {
            flex: 0 0 auto;
            width: 56px;
            height: 56px;
            background: #f1f5f9;
            border: 1px dashed #cbd5e1;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            font-size: 9px;
            text-align: center;
            padding: 4px;
            line-height: 1.2;
        }
        .emitter .emitter-info { flex: 1; min-width: 0; }
        .emitter h1 {
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 1px;
            line-height: 1.2;
        }
        .emitter .activity {
            font-size: 9.5px;
            color: #475569;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.3px;
            margin-bottom: 3px;
            line-height: 1.3;
        }
        .emitter .meta {
            font-size: 10px;
            color: #334155;
            line-height: 1.45;
        }
        .emitter .meta strong { color: #0f172a; font-weight: 700; }

        .document {
            flex: 0 0 230px;
            text-align: right;
        }
        .document .doc-badge {
            display: inline-block;
            background: #0f172a;
            color: white;
            padding: 4px 18px;
            font-size: 14px;
            font-weight: 800;
            letter-spacing: 2.5px;
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        .document .grid {
            display: grid;
            grid-template-columns: auto auto;
            gap: 3px 10px;
            justify-content: end;
            font-size: 10px;
            line-height: 1.3;
        }
        .document .grid .k {
            color: #64748b;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 8.5px;
            letter-spacing: 0.4px;
            text-align: right;
            align-self: center;
        }
        .document .grid .v {
            color: #0f172a;
            font-weight: 700;
            font-size: 11px;
            font-variant-numeric: tabular-nums;
            text-align: right;
        }
        .document .grid .v.cai {
            font-family: 'Courier New', monospace;
            font-size: 9.5px;
            word-break: break-all;
            max-width: 170px;
        }
        .document .grid .v.range {
            font-family: 'Courier New', monospace;
            font-size: 9px;
            font-weight: 600;
            line-height: 1.45;
        }
        .document .grid .v.range .range-row {
            display: flex;
            justify-content: flex-end;
            gap: 4px;
            white-space: nowrap;
        }
        .document .grid .v.range .range-row .lbl {
            color: #64748b;
            font-weight: 700;
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 8.5px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .sin-cai-badge {
            display: inline-block;
            background: #fef2f2;
            border: 1px solid #fca5a5;
            color: #991b1b;
            padding: 3px 8px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-top: 5px;
        }

        /* ═══ Cliente ═══ */
        .customer {
            display: flex;
            gap: 20px;
            padding: 8px 12px;
            background: #f8fafc;
            border-left: 3px solid #0f172a;
            margin-bottom: 10px;
        }
        .customer .field { flex: 1; min-width: 0; }
        .customer .field .k {
            font-size: 8.5px;
            color: #64748b;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 1px;
        }
        .customer .field .v {
            font-size: 11.5px;
            color: #0f172a;
            font-weight: 600;
        }

        /* ═══ Tabla items ═══ */
        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        table.items thead { background: #0f172a; color: white; }
        table.items thead th {
            padding: 6px 8px;
            font-size: 9.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: left;
        }
        table.items thead th.num { text-align: right; }
        table.items thead th.center { text-align: center; }

        table.items tbody td {
            padding: 6px 8px;
            font-size: 10.5px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: top;
            color: #1e293b;
        }
        table.items tbody tr:nth-child(even) { background: #fafbfc; }
        table.items td.num {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }
        table.items td.center { text-align: center; }
        table.items td .sku {
            display: block;
            font-size: 9px;
            color: #64748b;
            font-family: 'Courier New', monospace;
            margin-top: 1px;
        }
        table.items .tag {
            display: inline-block;
            padding: 1px 6px;
            font-size: 9px;
            font-weight: 700;
            border-radius: 2px;
            letter-spacing: 0.2px;
        }
        table.items .tag.gravado { background: #dbeafe; color: #1e40af; }
        table.items .tag.exento  { background: #f1f5f9; color: #475569; }

        table.items tr.empty td {
            text-align: center;
            color: #94a3b8;
            padding: 14px;
            font-style: italic;
        }

        /* ═══ Total en letras ═══ */
        .amount-words {
            display: flex;
            align-items: baseline;
            gap: 10px;
            padding: 7px 12px;
            background: #0f172a;
            color: white;
            margin-bottom: 10px;
            font-size: 10.5px;
        }
        .amount-words .lbl {
            font-size: 8.5px;
            font-weight: 700;
            color: #cbd5e1;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            flex: 0 0 auto;
        }
        .amount-words .val {
            font-weight: 700;
            letter-spacing: 0.3px;
            font-size: 11px;
        }

        /* ═══ Mid-grid: Pago (izq) · Totales (der) ═══ */
        .mid-grid {
            display: grid;
            grid-template-columns: 1fr 260px;
            gap: 14px;
            margin-bottom: 10px;
            align-items: stretch;
        }

        .payment-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
            border: 1px solid #e2e8f0;
            align-content: start;
        }
        .payment-grid .cell {
            padding: 6px 10px;
            border-right: 1px solid #e2e8f0;
            border-bottom: 1px solid #e2e8f0;
        }
        .payment-grid .cell:nth-child(2n) { border-right: none; }
        .payment-grid .cell:nth-last-child(-n+2) { border-bottom: none; }
        .payment-grid .cell .k {
            font-size: 8px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }
        .payment-grid .cell .v {
            font-size: 10px;
            color: #0f172a;
            min-height: 14px;
            font-weight: 500;
        }

        .totals { font-size: 11px; }
        .totals .row {
            display: flex;
            justify-content: space-between;
            padding: 3px 0;
            color: #475569;
        }
        .totals .row .k { color: #64748b; }
        .totals .row .v {
            font-variant-numeric: tabular-nums;
            font-weight: 600;
            color: #1e293b;
        }
        .totals .row.discount .v { color: #dc2626; }
        .totals .row.grand {
            border-top: 2px solid #0f172a;
            padding-top: 7px;
            margin-top: 5px;
            font-size: 14px;
            font-weight: 800;
        }
        .totals .row.grand .k { color: #0f172a; letter-spacing: 1px; }
        .totals .row.grand .v {
            color: #0f172a;
            font-size: 16px;
            font-weight: 800;
        }

        /* ═══ Hash SHA-256 de integridad ═══ */
        .integrity-box {
            margin-top: 14px;
            padding: 10px 14px;
            background: #f8fafc;
            border: 1px dashed #94a3b8;
            border-radius: 3px;
            line-height: 1.5;
        }
        .integrity-box .label {
            font-weight: 700;
            text-transform: uppercase;
            font-size: 8.5px;
            color: #0f172a;
            display: block;
            margin-bottom: 4px;
            letter-spacing: 0.6px;
        }
        .integrity-box .hash {
            font-family: 'Courier New', monospace;
            font-size: 10px;
            color: #334155;
            word-break: break-all;
        }

        /* ═══ Footer legal ═══ */
        .legal-footer {
            margin-top: 14px;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
            text-align: center;
        }
        .legal-footer .legend {
            font-size: 11px;
            font-weight: 700;
            color: #0f172a;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 3px;
        }
        .legal-footer .meta {
            font-size: 9px;
            color: #94a3b8;
            line-height: 1.5;
        }
        .legal-footer .consulted {
            margin-top: 6px;
            font-size: 8.5px;
            color: #64748b;
            font-style: italic;
        }

        /* ═══ Impresión bloqueada ═══
           La vista de verificación NO es imprimible por diseño: su propósito
           es confirmar estado fiscal (VÁLIDA/ANULADA), no sustituir al
           comprobante fiscal. Si alguien fuerza Ctrl+P / Cmd+P se oculta
           todo el contenido y se muestra un aviso claro con referencia al
           documento original que SÍ es imprimible. */
        @media print {
            @page { size: Letter; margin: 0.75in; }
            html, body {
                background: white !important;
                color: #0f172a !important;
            }
            body > * { display: none !important; }
            body::before {
                content: "Esta vista es solo de verificación pública de autenticidad. No es un comprobante fiscal imprimible. Consulte el original de la factura emitida por el contribuyente.";
                display: block !important;
                padding: 1.5in 0.5in 0;
                text-align: center;
                font-size: 13px;
                font-weight: 600;
                line-height: 1.6;
                color: #0f172a;
                font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            }
            body::after {
                content: "Factura {{ $cai['invoice_number'] }} — {{ $company['name'] }}";
                display: block !important;
                margin-top: 18px;
                padding: 0 0.5in;
                text-align: center;
                font-size: 10px;
                color: #64748b;
                font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
                font-weight: 500;
            }
        }

        /* ═══ Mobile ═══ */
        @media screen and (max-width: 640px) {
            .page { padding: 16px; margin: 8px; }
            .header { flex-direction: column; gap: 12px; }
            .document { text-align: left; flex: 1; }
            .document .grid { justify-content: start; }
            .document .grid .k, .document .grid .v { text-align: left; }
            .customer { flex-direction: column; gap: 6px; }
            .mid-grid { grid-template-columns: 1fr; gap: 10px; }
            .amount-words { flex-wrap: wrap; }
            .status-banner { font-size: 12px; letter-spacing: 2px; }
            .watermark { font-size: 48px; letter-spacing: 4px; }
        }
    </style>
</head>
<body>
    {{-- ════════════════ BANNER DE ESTADO ════════════════ --}}
    @if ($isVoid)
        <div class="status-banner void">
            Factura Anulada
            <span class="sub">Este documento fue anulado y no tiene validez fiscal</span>
        </div>
    @else
        <div class="status-banner valid">
            Factura Válida
            <span class="sub">Documento fiscal autenticado por verificación SAR</span>
        </div>
    @endif

    {{-- ════════════════ WATERMARK ════════════════ --}}
    <div class="watermark">Verificación Pública</div>

    {{-- NOTA: esta vista NO lleva botón de imprimir por diseño.
         Es una consulta de autenticidad, no un documento imprimible.
         El bloqueo efectivo de Ctrl+P/Cmd+P está en @media print arriba. --}}

    <div class="page">
        {{-- ════════════════ AVISO SAR ════════════════ --}}
        <div class="verification-notice">
            <strong>Verificación Pública de Documento Fiscal.</strong>
            Esta página muestra la representación fiel de una factura emitida bajo el régimen
            de autoimpresión SAR (Acuerdo 481-2017). Los datos provienen del emisor y están
            protegidos por un hash de integridad SHA-256. Esta vista NO reemplaza al comprobante
            fiscal impreso, es una verificación de autenticidad.
        </div>

        {{-- ════════════════ HEADER ════════════════ --}}
        <div class="header">
            <div class="emitter">
                @if (!empty($company['logo_url']))
                    <img
                        class="logo"
                        src="{{ $company['logo_url'] }}"
                        alt="{{ $company['name'] }}"
                        onerror="this.outerHTML='<div class=\'logo-placeholder\'>Sin logo</div>'"
                    >
                @else
                    <div class="logo-placeholder">Sin logo</div>
                @endif

                <div class="emitter-info">
                    <h1>{{ $company['name'] }}</h1>
                    @if (!empty($company['business_type']))
                        <div class="activity">{{ $company['business_type'] }}</div>
                    @endif
                    <div class="meta">
                        @if (!empty($company['address']))
                            {{ $company['address'] }}<br>
                        @endif
                        <strong>RTN:</strong> {{ $company['rtn'] }}<br>
                        @if (!empty($company['phone']))
                            <strong>Teléfono:</strong> {{ $company['phone'] }}<br>
                        @endif
                        @if (!empty($company['email']))
                            <strong>Correo:</strong> {{ $company['email'] }}
                        @endif
                    </div>
                </div>
            </div>

            <div class="document">
                <div class="doc-badge">{{ $cai['without_cai'] ? 'Factura (Sin CAI)' : 'Factura' }}</div>
                <div class="grid">
                    <span class="k">No.</span>
                    <span class="v">{{ $cai['invoice_number'] }}</span>

                    <span class="k">Fecha</span>
                    <span class="v">{{ $invoice->invoice_date?->format('d/m/Y') }}</span>

                    @if (!$cai['without_cai'])
                        <span class="k">CAI</span>
                        <span class="v cai">{{ $cai['number'] }}</span>

                        @if ($cai['range_from'] && $cai['range_to'])
                            <span class="k">Rango<br>Autorizado</span>
                            <span class="v range">
                                <span class="range-row">
                                    <span class="lbl">Desde:</span>
                                    <span>{{ $cai['range_from'] }}</span>
                                </span>
                                <span class="range-row">
                                    <span class="lbl">Hasta:</span>
                                    <span>{{ $cai['range_to'] }}</span>
                                </span>
                            </span>
                        @endif

                        @if ($cai['expiration_date'])
                            <span class="k">Vence</span>
                            <span class="v">{{ $cai['expiration_date'] }}</span>
                        @endif
                    @endif
                </div>

                @if ($cai['without_cai'])
                    <div class="sin-cai-badge">Sin valor fiscal</div>
                @endif
            </div>
        </div>

        {{-- ════════════════ CLIENTE ════════════════ --}}
        <div class="customer">
            <div class="field">
                <div class="k">Cliente</div>
                <div class="v">{{ $customer['name'] }}</div>
            </div>
            <div class="field">
                <div class="k">RTN</div>
                <div class="v">{{ $customer['rtn'] ?: '—' }}</div>
            </div>
            <div class="field">
                <div class="k">Fecha emisión</div>
                <div class="v">{{ $invoice->invoice_date?->format('d/m/Y') }}</div>
            </div>
        </div>

        {{-- ════════════════ TABLA ITEMS ════════════════ --}}
        <table class="items">
            <thead>
                <tr>
                    <th style="width: 13%;">Código</th>
                    <th style="width: 36%;">Producto</th>
                    <th class="num" style="width: 13%;">Precio</th>
                    <th class="center" style="width: 9%;">Cant.</th>
                    <th class="center" style="width: 12%;">ISV</th>
                    <th class="num" style="width: 17%;">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    @php
                        $isGravado = ($item['tax_type'] ?? '') === 'gravado_15';
                    @endphp
                    <tr>
                        <td><span class="sku">{{ $item['sku'] ?? '—' }}</span></td>
                        <td>{{ $item['description'] }}</td>
                        <td class="num">L {{ $item['unit_price'] }}</td>
                        <td class="center">{{ $item['quantity'] }}</td>
                        <td class="center">
                            <span class="tag {{ $isGravado ? 'gravado' : 'exento' }}">
                                {{ $isGravado ? '15%' : 'Exento' }}
                            </span>
                        </td>
                        <td class="num">L {{ $item['line_total'] }}</td>
                    </tr>
                @empty
                    <tr class="empty">
                        <td colspan="6">Sin ítems en esta factura.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        {{-- ════════════════ TOTAL EN LETRAS ════════════════ --}}
        <div class="amount-words">
            <span class="lbl">Total en Letras:</span>
            <span class="val">{{ $totals['total_in_words'] }}</span>
        </div>

        {{-- ════════════════ MID GRID: Pago (izq) · Totales (der) ════════════════ --}}
        <div class="mid-grid">
            <div class="payment-grid">
                <div class="cell">
                    <div class="k">Forma de Pago</div>
                    <div class="v">{{ $paymentMethod }}</div>
                </div>
                <div class="cell">
                    <div class="k">Vendedor</div>
                    <div class="v">{{ $seller }}</div>
                </div>
                <div class="cell">
                    <div class="k">Condición</div>
                    <div class="v">{{ $invoice->sale_condition ?? 'Contado' }}</div>
                </div>
                <div class="cell">
                    <div class="k">Moneda</div>
                    <div class="v">Lempiras (HNL)</div>
                </div>
            </div>

            <div class="totals">
                @if ($totals['has_exempt'])
                    <div class="row">
                        <span class="k">Exento:</span>
                        <span class="v">L {{ $totals['exempt'] }}</span>
                    </div>
                @endif
                <div class="row">
                    <span class="k">Subtotal gravado:</span>
                    <span class="v">L {{ $totals['taxable'] }}</span>
                </div>
                <div class="row">
                    <span class="k">ISV 15%:</span>
                    <span class="v">L {{ $totals['isv'] }}</span>
                </div>
                @if ($totals['has_discount'])
                    <div class="row discount">
                        <span class="k">Descuento:</span>
                        <span class="v">- L {{ $totals['discount'] }}</span>
                    </div>
                @endif
                <div class="row grand">
                    <span class="k">TOTAL</span>
                    <span class="v">L {{ $totals['total'] }}</span>
                </div>
            </div>
        </div>

        {{-- ════════════════ HASH DE INTEGRIDAD ════════════════ --}}
        {{-- El hash SHA-256 es la prueba criptográfica de no-repudio: si algún
             byte de la factura cambiara después de emitida, este valor sería
             distinto. Es el ancla de verificación pública del documento. --}}
        <div class="integrity-box">
            <span class="label">Hash de Integridad (SHA-256)</span>
            <div class="hash">{{ $invoice->integrity_hash }}</div>
        </div>

        {{-- ════════════════ LEYENDA ════════════════ --}}
        <div class="legal-footer">
            <div class="legend">{{ $footerLegend }}</div>
            <div class="meta">
                {{ $software['name'] }} v{{ $software['version'] }} · {{ $software['developer'] }} · {{ ucfirst($software['structure']) }}
            </div>
            <div class="consulted">
                Verificación consultada el {{ now()->format('d/m/Y H:i') }}
            </div>
        </div>
    </div>
</body>
</html>
