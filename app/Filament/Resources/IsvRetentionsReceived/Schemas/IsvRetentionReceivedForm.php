<?php

namespace App\Filament\Resources\IsvRetentionsReceived\Schemas;

use App\Enums\IsvRetentionType;
use App\Models\Establishment;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class IsvRetentionReceivedForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([

                // ── 1. Período y sucursal ───────────────────────────
                Section::make('Período fiscal')
                    ->aside()
                    ->description('Mes al que aplica la retención. Define en qué Formulario 201 se declara.')
                    ->schema([
                        Grid::make(3)->schema([
                            Select::make('period_year')
                                ->label('Año')
                                ->options(self::yearOptions())
                                ->default(now()->year)
                                ->required()
                                ->native(false),
                            Select::make('period_month')
                                ->label('Mes')
                                ->options(self::monthOptions())
                                ->default(now()->month)
                                ->required()
                                ->native(false),
                            Select::make('establishment_id')
                                ->label('Sucursal')
                                ->relationship(
                                    name: 'establishment',
                                    titleAttribute: 'name',
                                    modifyQueryUsing: fn ($query) => $query->where('is_active', true),
                                )
                                ->searchable()
                                ->preload()
                                ->native(false)
                                ->default(fn () => Establishment::main()->value('id'))
                                ->placeholder('Sin sucursal específica')
                                ->helperText('Opcional — sin sucursal = retención a nivel empresa.'),
                        ]),
                    ]),

                // ── 2. Tipo y agente retenedor ──────────────────────
                Section::make('Tipo de retención')
                    ->aside()
                    ->description('Define la casilla del Formulario 201 donde se declara.')
                    ->schema([
                        Select::make('retention_type')
                            ->label('Tipo')
                            ->options(IsvRetentionType::options())
                            ->required()
                            ->native(false)
                            ->helperText('Tarjetas: banco/procesador POS. Estado: organismos públicos. Acuerdo 215-2010: grandes contribuyentes.'),

                        Grid::make(2)->schema([
                            TextInput::make('agent_rtn')
                                ->label('RTN del agente retenedor')
                                ->required()
                                ->maxLength(14)
                                ->minLength(14)
                                ->regex('/^\d{14}$/')
                                ->validationMessages([
                                    'regex' => 'El RTN debe tener exactamente 14 dígitos sin guiones.',
                                ])
                                ->placeholder('06459877498120'),
                            TextInput::make('agent_name')
                                ->label('Nombre del agente retenedor')
                                ->required()
                                ->maxLength(200)
                                ->placeholder('Banco Atlántida, Tegucigalpa Muni, etc.'),
                        ]),
                    ]),

                // ── 3. Constancia y monto ───────────────────────────
                Section::make('Constancia y monto')
                    ->aside()
                    ->description('Documento emitido por el retenedor y monto retenido de ISV.')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('document_number')
                                ->label('# de constancia')
                                ->maxLength(50)
                                ->placeholder('Ej: CR-20260412-0001')
                                ->helperText('Opcional — algunos procesadores de tarjeta emiten solo reporte mensual sin numeración.'),
                            TextInput::make('amount')
                                ->label('Monto retenido')
                                ->required()
                                ->numeric()
                                ->minValue(0.01)
                                ->step(0.01)
                                ->prefix('L')
                                ->helperText('Siempre positivo, en lempiras.'),
                        ]),

                        // Constancia escaneada: el contador la sube para tener el soporte
                        // al alcance al armar la declaración en SIISAR. Disk 'public' +
                        // path organizado por año/mes para que el backup del storage
                        // preserve la estructura fiscal.
                        FileUpload::make('document_path')
                            ->label('Archivo de constancia (PDF o imagen)')
                            ->directory('isv-retentions')
                            ->disk('public')
                            ->visibility('private')
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                            ->maxSize(5120) // 5 MB
                            ->downloadable()
                            ->openable()
                            ->helperText('Adjuntar el escaneo o PDF de la constancia emitida por el retenedor.'),
                    ]),

                // ── 4. Notas ────────────────────────────────────────
                Section::make('Notas')
                    ->aside()
                    ->description('Observaciones internas.')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Textarea::make('notes')
                            ->label('')
                            ->rows(3)
                            ->maxLength(2000)
                            ->placeholder('Notas internas sobre esta retención'),
                    ]),
            ]);
    }

    /**
     * Años disponibles en el selector: año fiscal actual + 2 previos +
     * 1 futuro (para retenciones que lleguen con desfase al inicio del
     * siguiente ejercicio).
     */
    private static function yearOptions(): array
    {
        $current = now()->year;
        $years = range($current - 2, $current + 1);

        return array_combine($years, array_map('strval', $years));
    }

    private static function monthOptions(): array
    {
        return [
            1  => '01 — Enero',
            2  => '02 — Febrero',
            3  => '03 — Marzo',
            4  => '04 — Abril',
            5  => '05 — Mayo',
            6  => '06 — Junio',
            7  => '07 — Julio',
            8  => '08 — Agosto',
            9  => '09 — Septiembre',
            10 => '10 — Octubre',
            11 => '11 — Noviembre',
            12 => '12 — Diciembre',
        ];
    }
}
