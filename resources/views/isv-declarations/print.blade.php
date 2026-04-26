<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Declaración ISV {{ $period['label'] }} — {{ $company['name'] }}</title>

    {{-- CSS inline para que "Guardar como PDF" del navegador (desktop) y el
         share sheet nativo (iOS/Android) generen un PDF vectorial fiel.
         Cero dependencias externas — compatible con cloud hosting. --}}
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

        /* Banner de estado — reemplazada es rojo, vigente es verde discreto */
        .status-banner {
            text-align: center;
            padding: 10px;
            font-weight: bold;
            letter-spacing: 3px;
            margin-bottom: 16px;
            font-size: 13px;
            text-transform: uppercase;
        }
        .status-banner.active     { background: #e6f4ea; color: #1e8e3e; border: 1px solid #34a853; }
        .status-banner.superseded { background: #fce8e6; color: #c0392b; border: 1px solid #c0392b; }

        /* Header: empresa + título/metadatos del documento */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #111;
            padding-bottom: 16px;
            margin-bottom: 16px;
            gap: 24px;
        }
        .company { flex: 1; min-width: 0; }
        .company h1 { font-size: 18px; font-weight: bold; margin-bottom: 4px; }
        .company p { font-size: 11px; margin: 2px 0; }

        .doc-info { text-align: right; flex: 0 0 280px; }
        .doc-info h2 {
            font-size: 14px;
            letter-spacing: 1px;
            margin-bottom: 8px;
            text-transform: uppercase;
        }
        .doc-info .label {
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            color: #666;
        }
        .doc-info .value { font-size: 13px; font-weight: bold; }
        .doc-info .period {
            font-size: 20px;
            font-weight: bold;
            margin: 6px 0;
            letter-spacing: 1px;
        }
        .doc-info .rect {
            display: inline-block;
            margin-top: 6px;
            padding: 3px 8px;
            background: #f1f3f4;
            font-size: 11px;
            font-weight: bold;
            border-radius: 3px;
        }
        .doc-info .rect.n-gt-1 { background: #fef7e0; color: #a56316; }

        /* Secciones A/B/C */
        .section {
            margin: 20px 0;
            page-break-inside: avoid;
            border: 1px solid #e5e5e5;
            border-radius: 4px;
        }
        .section h3 {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 2px;
            background: #111;
            color: white;
            padding: 8px 12px;
            margin: 0;
        }
        .section table {
            width: 100%;
            border-collapse: collapse;
        }
        .section table td {
            padding: 8px 12px;
            font-size: 12px;
            border-bottom: 1px solid #eee;
        }
        .section table tr:last-child td { border-bottom: none; }
        .section table td.label { font-weight: 500; color: #333; }
        .section table td.amount {
            text-align: right;
            font-variant-numeric: tabular-nums;
            font-weight: bold;
            width: 200px;
        }
        .section table tr.total td {
            background: #fafafa;
            border-top: 2px solid #111;
            font-weight: bold;
        }
        .section table tr.highlight td.amount {
            background: #fef7e0;
            font-size: 14px;
        }
        .section table tr.highlight.success td.amount {
            background: #e6f4ea;
            color: #1e8e3e;
        }
        .section table tr.highlight.danger td.amount {
            background: #fce8e6;
            color: #c0392b;
        }

        /* Notes block (contador) */
        .notes {
            margin: 20px 0;
            padding: 12px 14px;
            background: #fafafa;
            border-left: 3px solid #666;
            font-size: 11px;
            page-break-inside: avoid;
        }
        .notes .label {
            font-size: 10px;
            font-weight: bold;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        /* SIISAR acuse */
        .siisar {
            margin: 16px 0;
            padding: 10px 14px;
            background: #f1f3f4;
            border: 1px dashed #999;
            font-size: 12px;
            page-break-inside: avoid;
        }
        .siisar .label {
            font-size: 10px;
            font-weight: bold;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .siisar .value {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            font-weight: bold;
            letter-spacing: 1px;
        }

        /* Firmas */
        .signatures {
            display: flex;
            gap: 40px;
            margin-top: 40px;
            page-break-inside: avoid;
        }
        .signatures .sign {
            flex: 1;
            text-align: center;
            font-size: 11px;
        }
        .signatures .sign .line {
            border-top: 1px solid #111;
            margin-bottom: 4px;
            padding-top: 6px;
        }
        .signatures .sign .role {
            font-weight: bold;
            text-transform: uppercase;
            font-size: 10px;
            color: #666;
        }
        .signatures .sign .name { font-size: 12px; font-weight: 500; }
        .signatures .sign .at   { font-size: 10px; color: #666; margin-top: 2px; }

        /* Pie */
        .footer {
            margin-top: 24px;
            padding-top: 12px;
            border-top: 1px solid #ccc;
            font-size: 9px;
            text-align: center;
            color: #666;
        }

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

        @media screen and (max-width: 640px) {
            .page { padding: 16px; margin: 8px; }
            .header { flex-direction: column; gap: 12px; }
            .doc-info { text-align: left; flex: 1; }
            .signatures { flex-direction: column; gap: 30px; }
        }
    </style>
</head>
<body>
    <div class="screen-actions">
        <button type="button" onclick="window.print()">Imprimir / Guardar como PDF</button>
    </div>

    <div class="page">
        {{-- Banner de estado: siempre visible para que un PDF archivado no se confunda --}}
        <div class="status-banner {{ $status['is_superseded'] ? 'superseded' : 'active' }}">
            {{ $status['label'] }}
        </div>

        <div class="header">
            <div class="company">
                <h1>{{ $company['name'] }}</h1>
                @if ($company['rtn'])
                    <p><strong>RTN:</strong> {{ $company['rtn'] }}</p>
                @endif
                @if ($company['address'])
                    <p>{{ $company['address'] }}</p>
                @endif
                @if ($company['phone'])
                    <p>Tel: {{ $company['phone'] }}</p>
                @endif
                @if ($company['email'])
                    <p>{{ $company['email'] }}</p>
                @endif
            </div>

            <div class="doc-info">
                <h2>Declaración ISV Mensual</h2>
                <div class="label">Formulario 201 — SAR Honduras</div>
                <div class="period">{{ $period['label'] }}</div>
                <div class="label">Snapshot</div>
                <div class="value">#{{ $declaration->id }}</div>
                <div class="rect {{ $status['rectificativa_number'] > 1 ? 'n-gt-1' : '' }}">
                    @if ($status['is_original'])
                        Declaración original
                    @else
                        Rectificativa #{{ $status['rectificativa_number'] - 1 }}
                    @endif
                </div>
            </div>
        </div>

        {{-- SECCIÓN A — VENTAS --}}
        <div class="section">
            <h3>Sección A — Ventas del período</h3>
            <table>
                <tr>
                    <td class="label">Ventas gravadas (sujetas a ISV 15%)</td>
                    <td class="amount">L {{ $sections['ventas']['gravadas'] }}</td>
                </tr>
                <tr>
                    <td class="label">Ventas exentas (canasta básica)</td>
                    <td class="amount">L {{ $sections['ventas']['exentas'] }}</td>
                </tr>
                <tr class="total">
                    <td class="label">Total de ventas del período</td>
                    <td class="amount">L {{ $sections['ventas']['totales'] }}</td>
                </tr>
            </table>
        </div>

        {{-- SECCIÓN B — COMPRAS --}}
        <div class="section">
            <h3>Sección B — Compras del período</h3>
            <table>
                <tr>
                    <td class="label">Compras gravadas (sujetas a ISV 15%)</td>
                    <td class="amount">L {{ $sections['compras']['gravadas'] }}</td>
                </tr>
                <tr>
                    <td class="label">Compras exentas</td>
                    <td class="amount">L {{ $sections['compras']['exentas'] }}</td>
                </tr>
                <tr class="total">
                    <td class="label">Total de compras del período</td>
                    <td class="amount">L {{ $sections['compras']['totales'] }}</td>
                </tr>
            </table>
        </div>

        {{-- SECCIÓN C — CÁLCULO DEL IMPUESTO --}}
        <div class="section">
            <h3>Sección C — Cálculo del impuesto</h3>
            <table>
                <tr>
                    <td class="label">ISV débito fiscal (15% sobre ventas gravadas)</td>
                    <td class="amount">L {{ $sections['isv']['debito_fiscal'] }}</td>
                </tr>
                <tr>
                    <td class="label">(−) ISV crédito fiscal (15% sobre compras gravadas)</td>
                    <td class="amount">L {{ $sections['isv']['credito_fiscal'] }}</td>
                </tr>
                <tr>
                    <td class="label">(−) Retenciones de ISV recibidas</td>
                    <td class="amount">L {{ $sections['isv']['retenciones_recibidas'] }}</td>
                </tr>
                <tr>
                    <td class="label">(−) Saldo a favor del período anterior</td>
                    <td class="amount">L {{ $sections['isv']['saldo_a_favor_anterior'] }}</td>
                </tr>
                <tr class="highlight danger">
                    <td class="label"><strong>ISV a pagar en este período</strong></td>
                    <td class="amount">L {{ $sections['isv']['isv_a_pagar'] }}</td>
                </tr>
                <tr class="highlight success">
                    <td class="label"><strong>Saldo a favor para el próximo período</strong></td>
                    <td class="amount">L {{ $sections['isv']['saldo_a_favor_siguiente'] }}</td>
                </tr>
            </table>
        </div>

        @if ($siisarAcuse)
            <div class="siisar">
                <div class="label">Acuse SIISAR</div>
                <div class="value">{{ $siisarAcuse }}</div>
            </div>
        @endif

        @if ($notes)
            <div class="notes">
                <div class="label">Notas del contador</div>
                {{ $notes }}
            </div>
        @endif

        <div class="signatures">
            <div class="sign">
                <div class="line">&nbsp;</div>
                <div class="role">Declaró</div>
                <div class="name">{{ $signatures['declared_by']['name'] }}</div>
                @if ($signatures['declared_by']['at'])
                    <div class="at">{{ $signatures['declared_by']['at'] }}</div>
                @endif
            </div>

            @if ($signatures['superseded_by'])
                <div class="sign">
                    <div class="line">&nbsp;</div>
                    <div class="role">Reemplazada por</div>
                    <div class="name">{{ $signatures['superseded_by']['name'] }}</div>
                    @if ($signatures['superseded_by']['at'])
                        <div class="at">{{ $signatures['superseded_by']['at'] }}</div>
                    @endif
                </div>
            @else
                <div class="sign">
                    <div class="line">&nbsp;</div>
                    <div class="role">Revisó / Aprobó</div>
                    <div class="name">&nbsp;</div>
                </div>
            @endif
        </div>

        <div class="footer">
            <p>
                Hoja de trabajo generada desde {{ config('fiscal.software.name', 'Sistema Diproma') }}
                el {{ $meta['printed_at'] }} por {{ $meta['printed_by'] }}.
            </p>
            <p>
                Este documento es de apoyo interno. El comprobante oficial de la declaración
                es el acuse generado por el portal SIISAR del SAR.
            </p>
        </div>
    </div>
</body>
</html>
