<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cierre de caja #{{ $session->id }} — {{ $company['name'] }}</title>

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

        /* Header: emisor (izq) + datos del documento (der) */
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

        .doc-info { text-align: right; flex: 0 0 260px; }
        .doc-info h2 { font-size: 16px; letter-spacing: 2px; margin-bottom: 8px; }
        .doc-info .label { font-size: 10px; font-weight: bold; text-transform: uppercase; color: #666; }
        .doc-info .value { font-size: 13px; font-weight: bold; }
        .doc-info .meta { margin-top: 4px; font-size: 10px; color: #444; }

        /* Franja resumen: sucursal / cajero / duración */
        .info-strip {
            display: flex;
            gap: 24px;
            margin: 16px 0;
            font-size: 12px;
            padding: 10px 12px;
            background: #fafafa;
            border-left: 3px solid #111;
        }
        .info-strip .field { flex: 1; }
        .info-strip .label {
            font-size: 10px;
            font-weight: bold;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 2px;
        }

        /* Bloque de saldos — el corazón del documento */
        .balances {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin: 16px 0;
        }
        .balance-card {
            padding: 12px;
            border: 1px solid #e5e5e5;
            border-radius: 4px;
            text-align: center;
        }
        .balance-card .label {
            font-size: 10px;
            font-weight: bold;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .balance-card .value {
            font-size: 16px;
            font-weight: bold;
            font-variant-numeric: tabular-nums;
        }
        .balance-card.expected { background: #fafafa; }
        .balance-card.discrepancy-exact    { background: #e6f4ea; border-color: #34a853; }
        .balance-card.discrepancy-positive { background: #fef7e0; border-color: #f9ab00; }
        .balance-card.discrepancy-negative { background: #fce8e6; border-color: #c0392b; }
        .balance-card.discrepancy-pending  { background: #f5f5f5; border-color: #ccc; color: #666; }
        .balance-card .subnote { font-size: 9px; color: #666; margin-top: 4px; }

        /* Secciones con título */
        .section { margin: 20px 0; page-break-inside: avoid; }
        .section h3 {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 1px solid #111;
            padding-bottom: 4px;
            margin-bottom: 8px;
        }

        /* Tablas */
        table.data { width: 100%; border-collapse: collapse; }
        table.data thead { background: #111; color: white; }
        table.data th {
            padding: 6px 10px;
            font-size: 10px;
            text-transform: uppercase;
            text-align: left;
            font-weight: bold;
        }
        table.data th.num { text-align: right; }
        table.data td {
            padding: 6px 10px;
            font-size: 11px;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }
        table.data td.num {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }
        table.data tfoot td {
            font-weight: bold;
            border-top: 2px solid #111;
            border-bottom: none;
            padding-top: 8px;
        }
        table.data tr { page-break-inside: avoid; }

        /* Colores de inflow/outflow en el kardex */
        .tag {
            display: inline-block;
            padding: 1px 6px;
            font-size: 9px;
            border-radius: 3px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .tag.in  { background: #e6f4ea; color: #1e8e3e; }
        .tag.out { background: #fce8e6; color: #c0392b; }
        .tag.neutral { background: #f1f3f4; color: #555; }

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
        .signatures .sign .name { font-size: 12px; }

        /* Pie */
        .footer {
            margin-top: 24px;
            padding-top: 12px;
            border-top: 1px solid #ccc;
            font-size: 9px;
            text-align: center;
            color: #666;
        }

        /* Banner "sesión abierta" — corte parcial */
        .open-banner {
            background: #f9ab00;
            color: white;
            text-align: center;
            padding: 10px;
            font-weight: bold;
            letter-spacing: 3px;
            margin-bottom: 16px;
            font-size: 14px;
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
            .balances { grid-template-columns: repeat(2, 1fr); }
            .info-strip { flex-direction: column; gap: 8px; }
            .signatures { flex-direction: column; gap: 30px; }
        }
    </style>
</head>
<body>
    <div class="screen-actions">
        <button type="button" onclick="window.print()">Imprimir / Guardar como PDF</button>
    </div>

    <div class="page">
        @if ($isOpen)
            <div class="open-banner">SESIÓN ABIERTA — CORTE PARCIAL</div>
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
                <h2>{{ $isOpen ? 'CORTE PARCIAL' : 'CIERRE DE CAJA' }}</h2>

                <div style="margin: 6px 0">
                    <span class="label">Sesión</span>
                    <span class="value">#{{ $session->id }}</span>
                </div>

                <div class="meta"><strong>Apertura:</strong> {{ $dates['opened_at'] }}</div>
                @if ($dates['closed_at'])
                    <div class="meta"><strong>Cierre:</strong> {{ $dates['closed_at'] }}</div>
                @endif
                @if ($dates['duration_human'])
                    <div class="meta"><strong>Duración:</strong> {{ $dates['duration_human'] }}</div>
                @endif
            </div>
        </div>

        {{-- ================= INFO STRIP ================= --}}
        <div class="info-strip">
            <div class="field">
                <div class="label">Sucursal</div>
                <div>{{ $establishment['name'] }} <small style="color:#888">({{ $establishment['code'] }})</small></div>
                @if (!empty($establishment['address']))
                    <div style="font-size:10px; color:#666; margin-top:2px">{{ $establishment['address'] }}</div>
                @endif
            </div>
            <div class="field">
                <div class="label">Cajero (apertura)</div>
                <div>{{ $people['opened_by'] }}</div>
            </div>
            @if ($people['closed_by'])
                <div class="field">
                    <div class="label">Cerrado por</div>
                    <div>{{ $people['closed_by'] }}</div>
                </div>
            @endif
            @if ($people['authorized_by'])
                <div class="field">
                    <div class="label">Autorizado por</div>
                    <div>{{ $people['authorized_by'] }}</div>
                </div>
            @endif
        </div>

        {{-- ================= SALDOS ================= --}}
        <div class="balances">
            <div class="balance-card">
                <div class="label">Apertura</div>
                <div class="value">L {{ $balances['opening'] }}</div>
            </div>
            <div class="balance-card expected">
                <div class="label">Esperado al cierre</div>
                <div class="value">L {{ $balances['expected'] }}</div>
                <div class="subnote">Apertura + ingresos − egresos (efectivo)</div>
            </div>
            <div class="balance-card">
                <div class="label">Contado físicamente</div>
                <div class="value">
                    @if ($balances['actual'])
                        L {{ $balances['actual'] }}
                    @else
                        —
                    @endif
                </div>
            </div>
            <div class="balance-card discrepancy-{{ $balances['discrepancy_sign'] }}">
                <div class="label">Descuadre</div>
                <div class="value">
                    @if ($balances['discrepancy'] !== null)
                        @if ($balances['discrepancy_raw'] > 0)
                            + L {{ $balances['discrepancy'] }}
                        @else
                            L {{ $balances['discrepancy'] }}
                        @endif
                    @else
                        Pendiente
                    @endif
                </div>
                <div class="subnote">
                    @switch ($balances['discrepancy_sign'])
                        @case('exact') Cuadre exacto @break
                        @case('positive') Sobrante @break
                        @case('negative') Faltante @break
                        @default Tolerancia: L {{ $balances['tolerance'] }}
                    @endswitch
                </div>
            </div>
        </div>

        {{-- ================= FLUJO DE EFECTIVO ================= --}}
        <div class="section">
            <h3>Flujo de efectivo</h3>
            <table class="data">
                <tbody>
                    <tr>
                        <td>Ingresos en efectivo del día</td>
                        <td class="num">L {{ $cashFlow['inflows'] }}</td>
                    </tr>
                    <tr>
                        <td>Egresos en efectivo del día</td>
                        <td class="num">L {{ $cashFlow['outflows'] }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- ================= POR MÉTODO DE PAGO ================= --}}
        @if (count($byPaymentMethod) > 0)
            <div class="section">
                <h3>Ventas por método de pago</h3>
                <table class="data">
                    <thead>
                        <tr>
                            <th>Método</th>
                            <th class="num">Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($byPaymentMethod as $row)
                            <tr>
                                <td>{{ $row['label'] }}</td>
                                <td class="num">L {{ $row['amount'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- ================= POR CATEGORÍA DE GASTO ================= --}}
        @if (count($byExpenseCategory) > 0)
            <div class="section">
                <h3>Gastos de caja chica por categoría</h3>
                <table class="data">
                    <thead>
                        <tr>
                            <th>Categoría</th>
                            <th class="num">Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($byExpenseCategory as $row)
                            <tr>
                                <td>{{ $row['label'] }}</td>
                                <td class="num">L {{ $row['amount'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- ================= KARDEX ================= --}}
        <div class="section">
            <h3>Detalle de movimientos</h3>
            <table class="data">
                <thead>
                    <tr>
                        <th>Hora</th>
                        <th>Tipo</th>
                        <th>Método</th>
                        <th>Descripción</th>
                        <th>Usuario</th>
                        <th class="num">Monto</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($movements as $m)
                        <tr>
                            <td>{{ $m['occurred_at'] }}</td>
                            <td>
                                <span class="tag {{ $m['is_inflow'] ? 'in' : ($m['is_outflow'] ? 'out' : 'neutral') }}">
                                    {{ $m['type_label'] }}
                                </span>
                            </td>
                            <td>{{ $m['method_label'] }}</td>
                            <td>
                                {{ $m['description'] ?: '—' }}
                                @if (!empty($m['category_label']))
                                    <div style="font-size:9px; color:#888; margin-top:1px">{{ $m['category_label'] }}</div>
                                @endif
                            </td>
                            <td>{{ $m['user_name'] }}</td>
                            <td class="num">L {{ $m['amount'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="text-align:center; color:#888; padding:20px">
                                Sin movimientos registrados en esta sesión.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- ================= NOTAS ================= --}}
        @if (!empty($session->notes))
            <div class="section">
                <h3>Notas del cierre</h3>
                <div style="font-size:11px; padding:8px 12px; background:#fafafa; border-left:3px solid #111;">
                    {{ $session->notes }}
                </div>
            </div>
        @endif

        {{-- ================= FIRMAS ================= --}}
        @if (!$isOpen)
            <div class="signatures">
                <div class="sign">
                    <div class="line"></div>
                    <div class="role">Cajero</div>
                    <div class="name">{{ $people['closed_by'] ?? $people['opened_by'] }}</div>
                </div>
                @if ($people['authorized_by'])
                    <div class="sign">
                        <div class="line"></div>
                        <div class="role">Autoriza (descuadre)</div>
                        <div class="name">{{ $people['authorized_by'] }}</div>
                    </div>
                @endif
            </div>
        @endif

        {{-- ================= FOOTER ================= --}}
        <div class="footer">
            Impreso por <strong>{{ $meta['printed_by'] }}</strong> el {{ $meta['printed_at'] }}
            · Sesión #{{ $session->id }}
            @if ($isOpen)
                · <strong style="color:#c0392b">Corte parcial — no sustituye cierre final</strong>
            @endif
        </div>
    </div>
</body>
</html>
