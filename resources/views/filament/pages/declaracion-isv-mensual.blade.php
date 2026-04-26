<x-filament-panels::page>
    {{-- ═══════════════════════════════════════════════════════
         Selector de período (form Livewire)
         La Page carga el período solo cuando el usuario dispara la
         header action "Cargar período" — evita queries reactivas
         por cada cambio de select.
         ═══════════════════════════════════════════════════════ --}}
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    @if ($this->loadedFiscalPeriodId !== null)
        {{-- ═══════════════════════════════════════════════════════
             Header del período cargado con badge de estado
             ═══════════════════════════════════════════════════════ --}}
        @php
            $statusLabel = match ($this->periodStatus) {
                'open' => 'Abierto',
                'declared' => 'Declarado al SAR',
                'reopened' => 'Reabierto para rectificativa',
                default => '—',
            };
            $statusColor = match ($this->periodStatus) {
                'open' => 'info',
                'declared' => 'success',
                'reopened' => 'warning',
                default => 'gray',
            };
            $statusIcon = match ($this->periodStatus) {
                'open' => 'heroicon-o-document-plus',
                'declared' => 'heroicon-o-lock-closed',
                'reopened' => 'heroicon-o-lock-open',
                default => 'heroicon-o-question-mark-circle',
            };
        @endphp

        <x-filament::section>
            <div class="flex items-center justify-between gap-4 flex-wrap">
                <div>
                    <h2 class="text-xl font-semibold text-gray-950 dark:text-white capitalize">
                        {{ $this->getLoadedPeriodLabel() }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Formulario 201 — Declaración Mensual del Impuesto Sobre Ventas
                    </p>
                </div>

                <x-filament::badge :color="$statusColor" :icon="$statusIcon" size="lg">
                    {{ $statusLabel }}
                </x-filament::badge>
            </div>
        </x-filament::section>

        {{-- ═══════════════════════════════════════════════════════
             Snapshot activo (si existe)
             Card compacta con metadata de la declaración vigente.
             ═══════════════════════════════════════════════════════ --}}
        @if ($this->activeSnapshot !== null)
            @php $s = $this->activeSnapshot; @endphp

            <x-filament::section
                icon="heroicon-o-shield-check"
                icon-color="success"
            >
                <x-slot name="heading">
                    Declaración vigente (Snapshot #{{ $s['id'] }})
                </x-slot>
                <x-slot name="description">
                    Esta es la versión actualmente presentada ante el SAR. Para corregirla, reabra el período y presente una rectificativa.
                </x-slot>

                <dl class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
                    <div>
                        <dt class="font-medium text-gray-500 dark:text-gray-400">Presentada</dt>
                        <dd class="mt-1 text-gray-950 dark:text-white">
                            {{ $s['declared_at'] ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500 dark:text-gray-400">Declarada por</dt>
                        <dd class="mt-1 text-gray-950 dark:text-white">
                            {{ $s['declared_by_name'] ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500 dark:text-gray-400">Acuse SIISAR</dt>
                        <dd class="mt-1 text-gray-950 dark:text-white font-mono">
                            {{ $s['siisar_acuse_number'] ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500 dark:text-gray-400">ISV a pagar</dt>
                        <dd class="mt-1 text-lg font-semibold {{ $s['isv_a_pagar'] > 0 ? 'text-danger-600 dark:text-danger-400' : 'text-success-600 dark:text-success-400' }}">
                            L. {{ number_format($s['isv_a_pagar'], 2) }}
                        </dd>
                    </div>
                </dl>

                @if (! empty($s['notes']))
                    <div class="mt-4 pt-4 border-t border-gray-200 dark:border-white/10">
                        <dt class="font-medium text-sm text-gray-500 dark:text-gray-400">Notas</dt>
                        <dd class="mt-1 text-sm text-gray-950 dark:text-white whitespace-pre-wrap">{{ $s['notes'] }}</dd>
                    </div>
                @endif
            </x-filament::section>
        @endif

        {{-- ═══════════════════════════════════════════════════════
             Totales calculados (preview en vivo desde libros SAR)
             Tres secciones alineadas con el Formulario 201:
               A. Ventas | B. Compras | C. Cálculo ISV
             Si hay snapshot activo, el título aclara que son totales
             recalculados (pueden diferir del snapshot si hubo
             movimientos posteriores — eso es lo que fuerza la rectificativa).
             ═══════════════════════════════════════════════════════ --}}
        @if ($this->computedTotals !== null)
            @php $t = $this->computedTotals; @endphp

            <x-filament::section
                icon="heroicon-o-calculator"
                icon-color="primary"
            >
                <x-slot name="heading">
                    @if ($this->activeSnapshot !== null)
                        Totales recalculados al día de hoy
                    @else
                        Totales calculados del período
                    @endif
                </x-slot>
                <x-slot name="description">
                    @if ($this->activeSnapshot !== null && $this->periodStatus === 'reopened')
                        Estos son los totales que se persistirán si presenta la rectificativa ahora. Compárelos contra el snapshot activo arriba para verificar los ajustes.
                    @elseif ($this->activeSnapshot !== null)
                        Estos son los totales al estado operativo actual. Si difieren del snapshot vigente, significa que hubo movimientos posteriores a la declaración — considere reabrir el período.
                    @else
                        Totales derivados en vivo desde los libros SAR + retenciones del período. Son los mismos que se persistirán si declara ahora.
                    @endif
                </x-slot>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {{-- Sección A — Ventas --}}
                    <div class="space-y-3">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white uppercase tracking-wide">
                            A. Ventas
                        </h3>
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-gray-500 dark:text-gray-400">Gravadas</dt>
                                <dd class="font-mono text-gray-950 dark:text-white">L. {{ number_format($t['ventas_gravadas'], 2) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500 dark:text-gray-400">Exentas</dt>
                                <dd class="font-mono text-gray-950 dark:text-white">L. {{ number_format($t['ventas_exentas'], 2) }}</dd>
                            </div>
                            <div class="flex justify-between pt-2 border-t border-gray-200 dark:border-white/10">
                                <dt class="font-semibold text-gray-950 dark:text-white">Total</dt>
                                <dd class="font-mono font-semibold text-gray-950 dark:text-white">L. {{ number_format($t['ventas_totales'], 2) }}</dd>
                            </div>
                        </dl>
                    </div>

                    {{-- Sección B — Compras --}}
                    <div class="space-y-3">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white uppercase tracking-wide">
                            B. Compras
                        </h3>
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-gray-500 dark:text-gray-400">Gravadas</dt>
                                <dd class="font-mono text-gray-950 dark:text-white">L. {{ number_format($t['compras_gravadas'], 2) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500 dark:text-gray-400">Exentas</dt>
                                <dd class="font-mono text-gray-950 dark:text-white">L. {{ number_format($t['compras_exentas'], 2) }}</dd>
                            </div>
                            <div class="flex justify-between pt-2 border-t border-gray-200 dark:border-white/10">
                                <dt class="font-semibold text-gray-950 dark:text-white">Total</dt>
                                <dd class="font-mono font-semibold text-gray-950 dark:text-white">L. {{ number_format($t['compras_totales'], 2) }}</dd>
                            </div>
                        </dl>
                    </div>

                    {{-- Sección C — Cálculo ISV --}}
                    <div class="space-y-3">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white uppercase tracking-wide">
                            C. Cálculo ISV
                        </h3>
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-gray-500 dark:text-gray-400">Débito fiscal</dt>
                                <dd class="font-mono text-gray-950 dark:text-white">L. {{ number_format($t['isv_debito_fiscal'], 2) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500 dark:text-gray-400">(−) Crédito fiscal</dt>
                                <dd class="font-mono text-gray-950 dark:text-white">L. {{ number_format($t['isv_credito_fiscal'], 2) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500 dark:text-gray-400">(−) Retenciones</dt>
                                <dd class="font-mono text-gray-950 dark:text-white">L. {{ number_format($t['isv_retenciones_recibidas'], 2) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500 dark:text-gray-400">(−) Saldo anterior</dt>
                                <dd class="font-mono text-gray-950 dark:text-white">L. {{ number_format($t['saldo_a_favor_anterior'], 2) }}</dd>
                            </div>

                            @if ($t['isv_a_pagar'] > 0)
                                <div class="flex justify-between pt-2 border-t border-gray-200 dark:border-white/10">
                                    <dt class="font-semibold text-danger-600 dark:text-danger-400">ISV a pagar</dt>
                                    <dd class="font-mono font-semibold text-danger-600 dark:text-danger-400">L. {{ number_format($t['isv_a_pagar'], 2) }}</dd>
                                </div>
                            @else
                                <div class="flex justify-between pt-2 border-t border-gray-200 dark:border-white/10">
                                    <dt class="font-semibold text-success-600 dark:text-success-400">Saldo a favor siguiente</dt>
                                    <dd class="font-mono font-semibold text-success-600 dark:text-success-400">L. {{ number_format($t['saldo_a_favor_siguiente'], 2) }}</dd>
                                </div>
                            @endif
                        </dl>
                    </div>
                </div>
            </x-filament::section>
        @endif

        {{-- ═══════════════════════════════════════════════════════
             Historial de rectificativas (snapshots supersedidos)
             Solo se renderiza si existen; en periodos sin rectificativas
             previas esta sección no aparece (menos ruido visual).
             ═══════════════════════════════════════════════════════ --}}
        @if (count($this->rectificativasHistory) > 0)
            <x-filament::section
                icon="heroicon-o-clock"
                icon-color="gray"
                collapsible
                collapsed
            >
                <x-slot name="heading">
                    Historial de rectificativas ({{ count($this->rectificativasHistory) }})
                </x-slot>
                <x-slot name="description">
                    Snapshots previos reemplazados por rectificativas posteriores. Son inmutables y quedan como rastro de auditoría.
                </x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-200 dark:border-white/10">
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <th class="py-2 pr-4">#</th>
                                <th class="py-2 pr-4">Presentada</th>
                                <th class="py-2 pr-4">Por</th>
                                <th class="py-2 pr-4">Acuse SIISAR</th>
                                <th class="py-2 pr-4">Reemplazada</th>
                                <th class="py-2 pr-4">Por</th>
                                <th class="py-2 pr-4 text-right">ISV a pagar</th>
                                <th class="py-2 text-right">Saldo a favor</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($this->rectificativasHistory as $r)
                                <tr>
                                    <td class="py-2 pr-4 font-mono text-gray-950 dark:text-white">{{ $r['id'] }}</td>
                                    <td class="py-2 pr-4 text-gray-950 dark:text-white">{{ $r['declared_at'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 text-gray-950 dark:text-white">{{ $r['declared_by_name'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 font-mono text-gray-500 dark:text-gray-400">{{ $r['siisar_acuse_number'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $r['superseded_at'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $r['superseded_by_name'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 text-right font-mono text-gray-950 dark:text-white">L. {{ number_format($r['isv_a_pagar'], 2) }}</td>
                                    <td class="py-2 text-right font-mono text-gray-950 dark:text-white">L. {{ number_format($r['saldo_a_favor_siguiente'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    @else
        {{-- Sin período cargado: ayuda contextual --}}
        <x-filament::section icon="heroicon-o-information-circle" icon-color="gray">
            <x-slot name="heading">¿Cómo funciona?</x-slot>

            <div class="prose dark:prose-invert max-w-none text-sm leading-relaxed">
                <ol class="list-decimal pl-6 space-y-2">
                    <li>
                        Seleccione el <strong>año y mes</strong> del período a declarar y presione
                        <em>Cargar período</em>. El sistema calculará los totales del Formulario 201
                        desde los libros de ventas y compras + retenciones recibidas.
                    </li>
                    <li>
                        Si el período está <strong>abierto</strong> y los totales son correctos, use
                        <em>Declarar al SAR</em> para cerrar el período y registrar el snapshot permanente.
                    </li>
                    <li>
                        Si detectó un error en una declaración anterior, use <em>Reabrir período</em> para
                        habilitar la corrección y luego <em>Presentar rectificativa</em> una vez revisados
                        los nuevos totales (Acuerdo SAR 189-2014).
                    </li>
                </ol>

                <p class="mt-4 text-gray-500 dark:text-gray-400 text-xs">
                    El <strong>mes en curso</strong> se puede cargar para ver totales parciales,
                    pero no puede declararse hasta que el mes haya terminado.
                </p>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
