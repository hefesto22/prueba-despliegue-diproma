<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Factura {{ $cai['invoice_number'] }} — {{ $company['name'] }}</title>

    {{--
        Factura imprimible — versión compacta.

        Optimización de altura: el objetivo es que una factura con pocos ítems
        quepa en ~media hoja Letter (≈5in de alto), dejando espacio para sello,
        firma o grapa. Factores clave de compresión:
          - Padding de página reducido (0.35in vs 0.55in)
          - Tipografía base 11px (antes 12-13px)
          - Logo 56×56 (antes 72×72)
          - Bloque cliente en una sola línea horizontal
          - SAR fields + QR + Totales en UNA SOLA fila de 3 columnas
          - Leyenda legal compacta en una línea final

        100% auto-contenida, sin Vite/Tailwind. Compatible con window.print().
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
        }

        /* ═══ Acciones (solo pantalla) ═══ */
        .screen-actions {
            max-width: 8.5in;
            margin: 14px auto 10px;
            display: flex;
            justify-content: flex-end;
            padding: 0 12px;
        }
        .screen-actions button {
            padding: 9px 20px;
            font-size: 13px;
            background: #0f172a;
            color: white;
            border: 0;
            cursor: pointer;
            border-radius: 5px;
            font-family: inherit;
            font-weight: 600;
            letter-spacing: 0.3px;
        }
        .screen-actions button:hover { background: #1e293b; }

        /* ═══ Página (Letter compacta) ═══ */
        .page {
            max-width: 8.5in;
            margin: 0 auto 30px;
            background: white;
            padding: 0.35in;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.1);
            position: relative;
        }

        /* ═══ Header ═══ */
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

        table.items tr { page-break-inside: avoid; }
        table.items tr.empty td {
            text-align: center;
            color: #94a3b8;
            padding: 14px;
            font-style: italic;
        }

        /* ═══ Total en letras (fila completa) ═══ */
        .amount-words {
            display: flex;
            align-items: baseline;
            gap: 10px;
            padding: 7px 12px;
            background: #0f172a;
            color: white;
            margin-bottom: 10px;
            font-size: 10.5px;
            page-break-inside: avoid;
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

        /* ═══ Mid-grid: Pago/Obs (izq) · Totales (der) ═══ */
        .mid-grid {
            display: grid;
            grid-template-columns: 1fr 260px;
            gap: 14px;
            margin-bottom: 10px;
            page-break-inside: avoid;
            align-items: stretch;
        }

        .info-panel {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .payment-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
            border: 1px solid #e2e8f0;
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
            border-bottom: 1px solid #cbd5e1;
            min-height: 14px;
            padding-bottom: 1px;
            font-weight: 500;
        }

        .observations {
            border: 1px solid #e2e8f0;
            padding: 6px 10px;
            flex: 1;
            min-height: 50px;
        }
        .observations .k {
            font-size: 8px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .observations .lines {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .observations .lines .line {
            border-bottom: 1px dotted #cbd5e1;
            height: 12px;
        }

        /* ═══ Bottom-grid: SAR+QR (izq) · Firma (der) ═══ */
        .bottom-grid {
            display: grid;
            grid-template-columns: 1fr 260px;
            gap: 14px;
            margin-top: 4px;
            page-break-inside: avoid;
            align-items: stretch;
        }

        .sar-qr-block {
            display: grid;
            grid-template-columns: 1fr 92px;
            gap: 10px;
            border: 1px solid #e2e8f0;
            padding: 8px 10px;
            background: #fafbfc;
        }
        .sar-qr-block .sar-fields {
            display: flex;
            flex-direction: column;
            gap: 3px;
            font-size: 9px;
            color: #475569;
        }
        .sar-qr-block .sar-fields .field-line {
            display: flex;
            gap: 4px;
            align-items: baseline;
            padding: 2px 0;
            border-bottom: 1px dotted #cbd5e1;
        }
        .sar-qr-block .sar-fields .field-line:last-child { border-bottom: none; }
        .sar-qr-block .sar-fields .field-line .lbl {
            color: #334155;
            font-weight: 600;
            flex: 0 0 auto;
            font-size: 8.5px;
        }
        .sar-qr-block .sar-fields .field-line .dots {
            flex: 1;
            border-bottom: 1px solid #94a3b8;
            height: 10px;
        }

        .sar-qr-block .qr-cell {
            text-align: center;
            font-size: 8px;
            color: #64748b;
            line-height: 1.2;
        }
        .sar-qr-block .qr-cell svg {
            display: block;
            margin: 0 auto 3px;
            width: 82px;
            height: 82px;
        }
        .sar-qr-block .qr-cell .qr-label {
            font-size: 8px;
            font-weight: 700;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .signature-block {
            border: 1px solid #e2e8f0;
            padding: 18px 16px 14px;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;   /* Firma al pie: más natural para firmar */
            background: #fafbfc;
        }
        .signature-block .sig {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
        .signature-block .sig .sig-line {
            width: 100%;
            border-top: 1px solid #0f172a;
            margin-bottom: 3px;
            min-height: 24px;
        }
        .signature-block .sig .sig-label {
            font-size: 9px;
            font-weight: 700;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .signature-block .sig .sig-hint {
            font-size: 8px;
            color: #64748b;
            margin-top: 1px;
        }

        .totals {
            font-size: 11px;
        }
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

        /* ═══ Pie legal ═══ */
        .legal-footer {
            margin-top: 12px;
            padding-top: 8px;
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

        /* ═══ Banner ANULADA ═══ */
        .void-banner {
            background: #dc2626;
            color: white;
            text-align: center;
            padding: 8px;
            font-weight: 800;
            letter-spacing: 4px;
            margin-bottom: 10px;
            font-size: 13px;
            text-transform: uppercase;
        }
        .void-watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-28deg);
            font-size: 110px;
            color: rgba(220, 38, 38, 0.09);
            font-weight: 900;
            letter-spacing: 14px;
            text-transform: uppercase;
            pointer-events: none;
            z-index: 10;
            white-space: nowrap;
        }

        /* ═══ Impresión ═══ */
        @media print {
            @page { size: Letter; margin: 0.35in; }
            html, body { background: white; font-size: 11px; }
            .screen-actions { display: none !important; }
            .page {
                box-shadow: none;
                margin: 0;
                padding: 0;
                max-width: 100%;
            }
            table.items thead {
                background: #0f172a !important;
                color: white !important;
            }
            table.items tbody tr:nth-child(even) { background: #fafbfc !important; }
            .document .doc-badge {
                background: #0f172a !important;
                color: white !important;
            }
            .void-banner { background: #dc2626 !important; color: white !important; }
        }

        /* ═══ Mobile ═══ */
        @media screen and (max-width: 640px) {
            .page { padding: 16px; margin: 8px; }
            .header { flex-direction: column; gap: 12px; }
            .document { text-align: left; flex: 1; }
            .document .grid { justify-content: start; }
            .document .grid .k, .document .grid .v { text-align: left; }
            .customer { flex-direction: column; gap: 6px; }
            /* En móvil las dos grids inferiores se colapsan a 1 columna
               para que nada se comprima a anchos ilegibles. */
            .mid-grid,
            .bottom-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            .sar-qr-block {
                grid-template-columns: 1fr;
            }
            .sar-qr-block .qr-cell { margin-top: 4px; }
            .amount-words { flex-wrap: wrap; }
        }
    </style>
</head>
<body>
    <div class="screen-actions">
        <button type="button" onclick="window.print()">Imprimir / Guardar como PDF</button>
    </div>

    <div class="page">
        @if ($isVoid)
            <div class="void-banner">FACTURA ANULADA</div>
            <div class="void-watermark">ANULADA</div>
        @endif

        {{-- ════════════════ HEADER ════════════════ --}}
        <div class="header">
            <div class="emitter">
                @if (!empty($company['logo_url']))
                    {{-- Si la URL existe pero la imagen no carga (storage:link
                         faltante, permisos, etc.) el onerror muta el <img> en
                         el placeholder en runtime — evita mostrar ícono roto. --}}
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
                    {{-- Datos de contacto de la empresa, uno por línea.
                         La dirección queda arriba (puede ser larga) y debajo
                         los identificadores cortos: RTN, teléfono, correo.
                         El label (strong) actúa como etiqueta visual consistente. --}}
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

                    {{-- "Punto Emis." (emission_point) se retiró a pedido del
                         cliente — el dato ya está embebido en el invoice_number
                         (segmento 3: "001-001-01-00000011" → 01 es el punto de
                         emisión), así que mostrarlo por separado era redundante. --}}
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

        {{-- ════════════════ TOTAL EN LETRAS (bar oscuro) ════════════════ --}}
        <div class="amount-words">
            <span class="lbl">Total en Letras:</span>
            <span class="val">{{ $totals['total_in_words'] }}</span>
        </div>

        {{-- ════════════════ MID GRID: Info pago/obs (izq) · Totales (der) ════════════════ --}}
        <div class="mid-grid">
            <div class="info-panel">
                {{-- Grid 2×2 de datos fiscales/comerciales auxiliares.
                     Las celdas tienen border-bottom sutil en .v para que
                     funcionen como renglón de llenado manual si la factura
                     se imprime en blanco y se rellena a mano. --}}
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

                <div class="observations">
                    <div class="k">Observaciones</div>
                    <div class="lines">
                        @if (!empty($invoice->notes))
                            <div>{{ $invoice->notes }}</div>
                        @else
                            <div class="line"></div>
                            <div class="line"></div>
                        @endif
                    </div>
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

        {{-- ════════════════ BOTTOM GRID: SAR+QR (izq) · Firmas (der) ════════════════ --}}
        <div class="bottom-grid">
            <div class="sar-qr-block">
                <div class="sar-fields">
                    <div class="field-line">
                        <span class="lbl">O. C. Exenta:</span>
                        <span class="dots"></span>
                    </div>
                    <div class="field-line">
                        <span class="lbl">Const. Reg. Exonerado:</span>
                        <span class="dots"></span>
                    </div>
                    <div class="field-line">
                        <span class="lbl">Identif. Reg. SAG:</span>
                        <span class="dots"></span>
                    </div>
                </div>

                {{-- QR compacto 82×82 (test con lectores comunes mantiene
                     legibilidad). La etiqueta debajo ancla visualmente
                     la funcionalidad de verificación SAR. --}}
                <div class="qr-cell">
                    {!! $qrSvg !!}
                    <div class="qr-label">Verificar</div>
                </div>
            </div>

            <div class="signature-block">
                {{-- Solo bloque "Entregado por". El "Recibido Conforme" se
                     retiró a pedido del cliente (la factura no exige firma
                     de recepción al cliente para ser válida ante SAR). --}}
                <div class="sig">
                    <div class="sig-line"></div>
                    <div class="sig-label">Entregado por</div>
                    <div class="sig-hint">Nombre y firma del emisor</div>
                </div>
            </div>
        </div>

        {{-- ════════════════ LEYENDA ════════════════ --}}
        <div class="legal-footer">
            <div class="legend">{{ $footerLegend }}</div>
            <div class="meta">
                Original: Cliente · Copia: Obligado Tributario
                &nbsp;|&nbsp;
                {{ $software['name'] }} v{{ $software['version'] }} · {{ $software['developer'] }} · {{ ucfirst($software['structure']) }}
            </div>
        </div>
    </div>
</body>
</html>
