<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="theme-color" content="#0f172a">
    <title>Reparación {{ $repair->repair_number }} · {{ $company['name'] }}</title>

    {{--
        Vista pública de tracking — mobile-first.

        Caso de uso primario: cliente escanea el QR del recibo desde su celular,
        aterriza aquí. Diseño optimizado para pantalla vertical de teléfono:
          - Header sticky con número + estado.
          - Timeline vertical con check verde por fase completada.
          - Galería de fotos con tap-to-zoom (target="_blank").
          - Total y saldo pendiente prominente.
          - Botón al recibo imprimible al final.

        Auto-contenida (sin Vite/Tailwind compilado). Compatible con cualquier
        navegador móvil moderno. Polling pasivo: si el cliente recarga, ve estado
        actualizado.
    --}}
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg: #f1f5f9;
            --surface: #ffffff;
            --text: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --primary: #0f172a;
            --success: #10b981;
            --success-bg: #d1fae5;
            --warning: #f59e0b;
            --warning-bg: #fef3c7;
            --danger: #dc2626;
            --danger-bg: #fee2e2;
            --info: #0ea5e9;
            --info-bg: #e0f2fe;
        }
        html, body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: var(--text);
            background: var(--bg);
            line-height: 1.5;
            font-size: 15px;
            -webkit-font-smoothing: antialiased;
        }
        .container {
            max-width: 480px;
            margin: 0 auto;
            padding: 16px 16px 40px;
        }

        /* ═══ Header ═══ */
        .brand {
            text-align: center;
            padding: 16px 8px 20px;
        }
        .brand .name {
            font-weight: 700;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text);
        }
        .brand .tag {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .hero {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 16px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
        }
        .hero .label {
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        .hero .number {
            font-family: 'SF Mono', Menlo, monospace;
            font-size: 22px;
            font-weight: 700;
            margin-top: 4px;
        }
        .hero .status-pill {
            display: inline-block;
            margin-top: 12px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.3px;
        }
        .status-recibido      { background: #f1f5f9; color: #475569; }
        .status-cotizado      { background: var(--info-bg); color: var(--info); }
        .status-aprobado      { background: var(--warning-bg); color: var(--warning); }
        .status-en_reparacion { background: #dbeafe; color: #1e40af; }
        .status-listo_entrega { background: var(--success-bg); color: var(--success); }
        .status-entregada     { background: var(--success-bg); color: var(--success); }
        .status-rechazada,
        .status-abandonada    { background: var(--danger-bg); color: var(--danger); }

        /* ═══ Card ═══ */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px 18px;
            margin-bottom: 12px;
        }
        .card h2 {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            font-weight: 700;
            margin-bottom: 12px;
        }

        /* ═══ Timeline ═══ */
        .timeline {
            position: relative;
            padding-left: 24px;
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 7px;
            top: 4px;
            bottom: 4px;
            width: 2px;
            background: var(--border);
        }
        .timeline-item {
            position: relative;
            padding: 6px 0 14px;
        }
        .timeline-item:last-child { padding-bottom: 0; }
        .timeline-item .dot {
            position: absolute;
            left: -24px;
            top: 8px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: var(--surface);
            border: 2px solid var(--border);
            box-sizing: border-box;
        }
        .timeline-item.reached .dot {
            background: var(--success);
            border-color: var(--success);
        }
        .timeline-item.reached .dot::after {
            content: '';
            position: absolute;
            top: 2px; left: 5px;
            width: 4px; height: 7px;
            border-right: 2px solid white;
            border-bottom: 2px solid white;
            transform: rotate(45deg);
        }
        .timeline-item .lbl {
            font-weight: 600;
            font-size: 14px;
        }
        .timeline-item .at {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
        }
        .timeline-item:not(.reached) .lbl { color: var(--text-muted); font-weight: 500; }

        /* ═══ Device ═══ */
        .device-row {
            display: flex;
            font-size: 14px;
            padding: 4px 0;
        }
        .device-row .k {
            width: 110px;
            color: var(--text-muted);
            font-weight: 500;
        }
        .device-row .v {
            flex: 1;
            color: var(--text);
            font-weight: 500;
        }
        .issue-block {
            background: #fffbeb;
            border-left: 3px solid var(--warning);
            padding: 10px 12px;
            margin-top: 10px;
            border-radius: 0 6px 6px 0;
            font-size: 14px;
        }

        /* ═══ Photos ═══ */
        .photos-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6px;
        }
        .photo-tile {
            aspect-ratio: 1;
            background: #f1f5f9;
            border-radius: 8px;
            overflow: hidden;
            position: relative;
        }
        .photo-tile img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .empty-photos {
            text-align: center;
            color: var(--text-muted);
            font-size: 13px;
            padding: 20px;
            font-style: italic;
        }

        /* ═══ Totals ═══ */
        .totals .row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 14px;
        }
        .totals .row.total {
            font-size: 17px;
            font-weight: 700;
            border-top: 1px solid var(--border);
            margin-top: 6px;
            padding-top: 12px;
        }
        .totals .row.advance .v { color: var(--success); }
        .totals .row.outstanding {
            background: var(--warning-bg);
            color: #92400e;
            padding: 8px 12px;
            border-radius: 6px;
            margin-top: 6px;
            font-weight: 700;
        }

        /* ═══ CTA ═══ */
        .cta {
            display: block;
            text-align: center;
            background: var(--primary);
            color: white;
            padding: 14px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            font-size: 15px;
            margin-top: 16px;
        }
        .cta:active { background: #1e293b; }

        .footer {
            text-align: center;
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 20px;
            padding: 10px;
        }
    </style>
</head>
<body>

<div class="container">

    <header class="brand">
        <div class="name">{{ $company['name'] }}</div>
        @if(filled($company['phone']))<div class="tag">Tel: {{ $company['phone'] }}</div>@endif
    </header>

    <section class="hero">
        <div class="label">Tu reparación</div>
        <div class="number">{{ $repair->repair_number }}</div>
        <span class="status-pill status-{{ $currentStatus->value }}">
            {{ $currentStatus->getLabel() }}
        </span>
    </section>

    <section class="card">
        <h2>Estado de tu reparación</h2>
        <div class="timeline">
            @foreach($timeline as $phase)
                <div class="timeline-item {{ $phase['reached'] ? 'reached' : '' }}">
                    <div class="dot"></div>
                    <div class="lbl">{{ $phase['label'] }}</div>
                    @if($phase['at'])
                        <div class="at">{{ $phase['at'] }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    <section class="card">
        <h2>Tu equipo</h2>
        <div class="device-row"><span class="k">Tipo:</span><span class="v">{{ $device['category'] }}</span></div>
        <div class="device-row"><span class="k">Marca:</span><span class="v">{{ $device['brand'] }}</span></div>
        @if($device['model'])
            <div class="device-row"><span class="k">Modelo:</span><span class="v">{{ $device['model'] }}</span></div>
        @endif
        @if($device['serial'])
            <div class="device-row"><span class="k">Serie:</span><span class="v">{{ $device['serial'] }}</span></div>
        @endif

        <div class="issue-block">
            <strong>Falla reportada:</strong><br>
            {{ $device['reported_issue'] }}
        </div>

        @if($device['diagnosis'] && in_array($currentStatus, [
            \App\Enums\RepairStatus::Cotizado,
            \App\Enums\RepairStatus::Aprobado,
            \App\Enums\RepairStatus::EnReparacion,
            \App\Enums\RepairStatus::ListoEntrega,
            \App\Enums\RepairStatus::Entregada,
        ], true))
            <div class="issue-block" style="background: #eff6ff; border-color: var(--info);">
                <strong>Diagnóstico técnico:</strong><br>
                {{ $device['diagnosis'] }}
            </div>
        @endif
    </section>

    @if(count($photos) > 0)
        <section class="card">
            <h2>Fotos del equipo</h2>
            <div class="photos-grid">
                @foreach($photos as $photo)
                    <a class="photo-tile" href="{{ $photo['url'] }}" target="_blank" rel="noopener">
                        <img src="{{ $photo['url'] }}" alt="{{ $photo['caption'] ?? $photo['purpose_label'] }}" loading="lazy">
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if($totals['has_quotation'])
        <section class="card">
            <h2>Resumen de pago</h2>
            <div class="totals">
                <div class="row total"><span>Total reparación</span><span class="v">L. {{ $totals['total'] }}</span></div>
                @if($totals['has_advance'])
                    <div class="row advance"><span>Anticipo entregado</span><span class="v">- L. {{ $totals['advance_payment'] }}</span></div>
                    <div class="row outstanding"><span>Saldo al entregar</span><span class="v">L. {{ $totals['outstanding'] }}</span></div>
                @endif
            </div>
        </section>
    @endif

    <a class="cta" href="{{ $printUrl }}">Ver recibo imprimible</a>

    <div class="footer">
        Esta página se actualiza automáticamente.<br>
        Recarga para ver el estado más reciente.
    </div>

</div>

</body>
</html>
