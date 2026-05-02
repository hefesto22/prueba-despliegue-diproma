<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cotización {{ $repair->repair_number }} — {{ $company['name'] }}</title>

    {{--
        Recibo Interno de Cotización — Reparación.

        IMPORTANTE: NO es un documento fiscal CAI. Es un comprobante interno
        que se entrega al cliente al recibir su equipo, para que decida si
        aprueba la reparación. La factura CAI se emite hasta la entrega.

        Diseño:
          - Una sola hoja Letter compacta (~5in si la cotización es corta).
          - QR grande y legible (apunta a /r/{qr_token} para consulta del cliente).
          - Sin logo — usa nombre + dirección + RTN del CompanySetting.
          - Footer con leyenda "NO ES DOCUMENTO FISCAL" para evitar que el
            cliente confunda esto con una factura.

        100% auto-contenido, window.print() compatible.
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

        .screen-actions {
            max-width: 8.5in;
            margin: 14px auto 10px;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
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
        .screen-actions .secondary {
            background: #64748b;
        }
        .screen-actions .secondary:hover { background: #475569; }

        .page {
            max-width: 8.5in;
            margin: 0 auto 30px;
            background: white;
            padding: 0.4in;
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
            margin-bottom: 14px;
        }
        .emitter { flex: 1; min-width: 0; }
        .emitter h1 {
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 2px;
        }
        .emitter .meta {
            font-size: 9.5px;
            color: #475569;
            line-height: 1.4;
        }
        .doc-block {
            text-align: right;
            min-width: 220px;
        }
        .doc-block .badge {
            display: inline-block;
            background: #fef3c7;
            color: #92400e;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 3px 8px;
            border-radius: 3px;
            margin-bottom: 5px;
        }
        .doc-block h2 {
            font-size: 14px;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
        }
        .doc-block .number {
            font-family: 'SF Mono', Menlo, monospace;
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
            letter-spacing: 0.5px;
            margin-top: 2px;
        }
        .doc-block .date {
            font-size: 10px;
            color: #475569;
            margin-top: 2px;
        }
        .doc-block .status {
            display: inline-block;
            padding: 3px 9px;
            font-size: 9.5px;
            font-weight: 700;
            border-radius: 3px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: #dbeafe;
            color: #1e40af;
            margin-top: 5px;
        }

        /* ═══ Bloques cliente / equipo ═══ */
        .info-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 12px;
        }
        .info-block {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 8px 10px;
        }
        .info-block h3 {
            font-size: 9px;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 0.5px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .info-block .row {
            display: flex;
            font-size: 11px;
            line-height: 1.5;
        }
        .info-block .row .label {
            color: #64748b;
            min-width: 65px;
            font-weight: 600;
        }
        .info-block .row .value {
            color: #0f172a;
            flex: 1;
        }

        /* ═══ Falla y diagnóstico ═══ */
        .note-block {
            background: #fffbeb;
            border-left: 3px solid #f59e0b;
            padding: 8px 10px;
            margin-bottom: 12px;
            border-radius: 0 4px 4px 0;
        }
        .note-block h3 {
            font-size: 9px;
            text-transform: uppercase;
            color: #92400e;
            letter-spacing: 0.5px;
            font-weight: 700;
            margin-bottom: 3px;
        }
        .note-block p {
            font-size: 11px;
            color: #1f2937;
            line-height: 1.5;
        }

        /* ═══ Tabla de items ═══ */
        table.items {
            width: 100%;
            border-collapse: collapse;
            font-size: 10.5px;
            margin-bottom: 10px;
        }
        table.items thead {
            background: #0f172a;
            color: white;
        }
        table.items th {
            padding: 6px 8px;
            text-align: left;
            font-weight: 600;
            font-size: 9.5px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        table.items th.num { text-align: right; }
        table.items td {
            padding: 5px 8px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }
        table.items td.num {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }
        table.items .source {
            font-size: 9px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            font-weight: 600;
        }
        table.items .empty {
            text-align: center;
            color: #94a3b8;
            padding: 14px;
            font-style: italic;
        }
        table.items tbody tr:nth-child(even) {
            background: #f8fafc;
        }

        /* ═══ QR + Totales ═══ */
        .footer-row {
            display: grid;
            grid-template-columns: 160px 1fr;
            gap: 14px;
            margin-top: 14px;
        }
        .qr-block {
            text-align: center;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 8px;
        }
        .qr-block svg {
            width: 130px;
            height: 130px;
            display: block;
            margin: 0 auto;
        }
        .qr-block .label {
            font-size: 9px;
            color: #64748b;
            margin-top: 4px;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.4px;
        }
        .qr-block .url {
            font-size: 8px;
            color: #94a3b8;
            font-family: 'SF Mono', Menlo, monospace;
            margin-top: 2px;
            word-break: break-all;
        }

        .totals {
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
        }
        .totals .row {
            display: flex;
            justify-content: space-between;
            padding: 3px 8px;
            font-size: 11px;
            border-bottom: 1px solid #f1f5f9;
        }
        .totals .row .label {
            color: #475569;
            font-weight: 600;
        }
        .totals .row .value {
            font-variant-numeric: tabular-nums;
            color: #0f172a;
        }
        .totals .row.total {
            background: #0f172a;
            color: white;
            border-radius: 4px;
            padding: 6px 10px;
            margin-top: 4px;
            border-bottom: 0;
            font-size: 13px;
            font-weight: 700;
        }
        .totals .row.total .label,
        .totals .row.total .value {
            color: white;
        }
        .totals .row.advance {
            color: #047857;
        }
        .totals .row.advance .value {
            color: #047857;
            font-weight: 700;
        }
        .totals .row.outstanding {
            background: #fef3c7;
            border-radius: 4px;
            padding: 5px 10px;
            margin-top: 4px;
            border-bottom: 0;
            font-weight: 700;
        }

        /* ═══ Footer ═══ */
        .signatures {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-top: 25px;
            padding-top: 14px;
        }
        .sig-line {
            border-top: 1px solid #94a3b8;
            padding-top: 5px;
            text-align: center;
            font-size: 9.5px;
            color: #475569;
        }

        .legal-footer {
            margin-top: 18px;
            padding-top: 10px;
            border-top: 1px dashed #cbd5e1;
            text-align: center;
            font-size: 9px;
            color: #64748b;
            line-height: 1.5;
        }
        .legal-footer .alert {
            background: #fef2f2;
            color: #991b1b;
            padding: 5px 10px;
            border-radius: 3px;
            font-weight: 700;
            display: inline-block;
            margin-bottom: 6px;
            font-size: 9.5px;
        }

        /* ═══ Print ═══ */
        @media print {
            body { background: white; }
            .screen-actions { display: none !important; }
            .page {
                box-shadow: none;
                margin: 0 auto;
                padding: 0.4in;
            }
            @page {
                size: Letter;
                margin: 0.3in;
            }
        }
    </style>
</head>
<body>

<div class="screen-actions">
    <button onclick="window.print()">Imprimir / Guardar PDF</button>
    <button class="secondary" onclick="window.close()">Cerrar</button>
</div>

<div class="page">

    <header class="header">
        <div class="emitter">
            <h1>{{ $company['name'] }}</h1>
            <div class="meta">
                @if(filled($company['rtn']))RTN: {{ $company['rtn'] }} · @endif
                {{ $company['address'] }}
                @if(filled($company['phone']))<br>Tel: {{ $company['phone'] }}@endif
                @if(filled($company['email'])) · {{ $company['email'] }}@endif
            </div>
        </div>
        <div class="doc-block">
            <div class="badge">Recibo Interno</div>
            <h2>Cotización de Reparación</h2>
            <div class="number">{{ $repair->repair_number }}</div>
            <div class="date">Recibido: {{ $repair->received_at?->format('d/m/Y H:i') }}</div>
            <div class="status">{{ $repair->status->getLabel() }}</div>
        </div>
    </header>

    <div class="info-row">
        <div class="info-block">
            <h3>Cliente</h3>
            <div class="row"><span class="label">Nombre:</span><span class="value">{{ $customer['name'] }}</span></div>
            <div class="row"><span class="label">Teléfono:</span><span class="value">{{ $customer['phone'] }}</span></div>
            @if($customer['has_rtn'])
                <div class="row"><span class="label">RTN:</span><span class="value">{{ $customer['rtn'] }}</span></div>
            @endif
        </div>
        <div class="info-block">
            <h3>Equipo</h3>
            <div class="row"><span class="label">Tipo:</span><span class="value">{{ $device['category'] }}</span></div>
            <div class="row"><span class="label">Marca:</span><span class="value">{{ $device['brand'] }} {{ $device['model'] !== '—' ? '· ' . $device['model'] : '' }}</span></div>
            @if($device['serial'] !== '—')
                <div class="row"><span class="label">Serie:</span><span class="value">{{ $device['serial'] }}</span></div>
            @endif
        </div>
    </div>

    <div class="note-block">
        <h3>Falla reportada por el cliente</h3>
        <p>{{ $device['reported_issue'] }}</p>
    </div>

    @if($device['diagnosis'] !== 'Pendiente')
        <div class="note-block">
            <h3>Diagnóstico técnico</h3>
            <p>{{ $device['diagnosis'] }}</p>
        </div>
    @endif

    <table class="items">
        <thead>
            <tr>
                <th>Descripción</th>
                <th class="num">Cant.</th>
                <th class="num">P. Unit.</th>
                <th>Tipo</th>
                <th class="num">Subtotal</th>
                <th class="num">ISV</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $item)
                <tr>
                    <td>
                        <div>{{ $item['description'] }}</div>
                        <div class="source">{{ $item['source_label'] }}@if($item['condition_label']) — {{ $item['condition_label'] }}@endif</div>
                    </td>
                    <td class="num">{{ $item['quantity'] }}</td>
                    <td class="num">L. {{ $item['unit_price'] }}</td>
                    <td>{{ $item['tax_label'] }}</td>
                    <td class="num">L. {{ $item['subtotal'] }}</td>
                    <td class="num">L. {{ $item['isv'] }}</td>
                    <td class="num"><strong>L. {{ $item['total'] }}</strong></td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">Sin líneas de cotización todavía</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer-row">
        <div class="qr-block">
            {!! $qrSvg !!}
            <div class="label">Estado en línea</div>
            <div class="url">{{ str_replace('https://', '', $qrUrl) }}</div>
        </div>

        <div class="totals">
            <div class="row"><span class="label">Subtotal exento:</span><span class="value">L. {{ $totals['exempt_total'] }}</span></div>
            <div class="row"><span class="label">Subtotal gravado:</span><span class="value">L. {{ $totals['taxable_total'] }}</span></div>
            <div class="row"><span class="label">ISV 15%:</span><span class="value">L. {{ $totals['isv'] }}</span></div>
            <div class="row total"><span class="label">TOTAL</span><span class="value">L. {{ $totals['total'] }}</span></div>
            @if($totals['has_advance'])
                <div class="row advance"><span class="label">Anticipo recibido:</span><span class="value">- L. {{ $totals['advance_payment'] }}</span></div>
                <div class="row outstanding"><span class="label">Saldo al entregar:</span><span class="value">L. {{ $totals['outstanding'] }}</span></div>
            @endif
        </div>
    </div>

    <div class="signatures">
        <div class="sig-line">Firma del cliente</div>
        <div class="sig-line">Recibido por: {{ $receivedBy }}</div>
    </div>

    <div class="legal-footer">
        <div class="alert">ESTE DOCUMENTO NO ES UNA FACTURA — RECIBO INTERNO DE COTIZACIÓN</div>
        <div>
            La factura fiscal con CAI se emite al entregar la reparación.<br>
            Este recibo sirve únicamente para identificar el equipo, validar la cotización aceptada por el cliente,
            y consultar el estado de la reparación escaneando el QR.
        </div>
    </div>

</div>

</body>
</html>
