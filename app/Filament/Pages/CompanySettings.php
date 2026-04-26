<?php

namespace App\Filament\Pages;

use App\Models\CompanySetting;
use App\Models\FiscalPeriod;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class CompanySettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static ?string $navigationLabel = 'Configuración Empresa';

    protected static ?string $title = 'Configuración de la Empresa';

    protected static ?string $slug = 'company-settings';

    protected static ?int $navigationSort = 99;

    protected string $view = 'filament.pages.company-settings';

    public static function getNavigationGroup(): ?string
    {
        return 'Administración';
    }

    // ─── Estado del formulario ──────────────────────────

    public ?array $data = [];

    public function mount(): void
    {
        $settings = CompanySetting::current();
        $this->form->fill($settings->toArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Datos Legales')
                    ->description('Información que aparecerá en todas las facturas y documentos fiscales')
                    ->icon('heroicon-o-building-office')
                    ->columns(2)
                    ->schema([
                        TextInput::make('legal_name')
                            ->label('Razón Social')
                            ->placeholder('Distribuidora Diproma S.A. de C.V.')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('trade_name')
                            ->label('Nombre Comercial')
                            ->placeholder('Diproma')
                            ->maxLength(255)
                            ->helperText('Si es diferente a la razón social'),

                        TextInput::make('rtn')
                            ->label('RTN')
                            ->placeholder('0801-1990-00001')
                            ->required()
                            ->maxLength(20),

                        TextInput::make('business_type')
                            ->label('Giro del Negocio')
                            ->placeholder('Distribución de equipos de cómputo')
                            ->maxLength(255),
                    ]),

                Section::make('Ubicación')
                    ->icon('heroicon-o-map-pin')
                    ->columns(2)
                    ->schema([
                        Textarea::make('address')
                            ->label('Dirección')
                            ->placeholder('Col. Las Torres, Blvd. del Norte, Edificio Plaza...')
                            ->required()
                            ->rows(2)
                            ->columnSpanFull(),

                        TextInput::make('city')
                            ->label('Ciudad')
                            ->placeholder('San Pedro Sula'),

                        TextInput::make('department')
                            ->label('Departamento')
                            ->placeholder('Cortés'),

                        TextInput::make('municipality')
                            ->label('Municipio')
                            ->placeholder('San Pedro Sula'),
                    ]),

                Section::make('Contacto')
                    ->icon('heroicon-o-phone')
                    ->columns(2)
                    ->schema([
                        TextInput::make('phone')
                            ->label('Teléfono Principal')
                            ->tel()
                            ->placeholder('+504 2555-0000'),

                        TextInput::make('phone_secondary')
                            ->label('Teléfono Secundario')
                            ->tel()
                            ->placeholder('+504 9999-0000'),

                        TextInput::make('email')
                            ->label('Correo Electrónico')
                            ->email()
                            ->placeholder('ventas@diproma.hn'),

                        TextInput::make('website')
                            ->label('Sitio Web')
                            ->url()
                            ->placeholder('https://diproma.hn'),
                    ]),

                Section::make('Branding')
                    ->icon('heroicon-o-photo')
                    ->schema([
                        FileUpload::make('logo_path')
                            ->label('Logo de la Empresa')
                            ->image()
                            ->disk('public')
                            ->directory('company')
                            ->maxSize(2048)
                            ->helperText('Aparecerá en facturas y documentos. Máximo 2MB.'),
                    ]),

                Section::make('Configuración Fiscal SAR')
                    ->description('Régimen tributario del obligado. Los códigos de establecimiento y punto de emisión se gestionan en Establecimientos.')
                    ->icon('heroicon-o-document-text')
                    ->columns(2)
                    ->schema([
                        Select::make('tax_regime')
                            ->label('Régimen Tributario')
                            ->options([
                                'normal' => 'Régimen Normal',
                                'simplificado' => 'Régimen Simplificado',
                            ])
                            ->native(false)
                            ->required(),

                        DatePicker::make('fiscal_period_start')
                            ->label('Inicio del Período Fiscal')
                            ->helperText(
                                'Primer mes que el sistema controla para declaración de ISV al SAR. '
                                . 'Debe ser el día 1 del mes. Las facturas emitidas antes de esta fecha '
                                . 'solo podrán corregirse por Nota de Crédito (nunca anulación directa). '
                                . '⚠️ Editar después de declarar períodos puede desalinear las declaraciones existentes.'
                            )
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->firstDayOfWeek(1)
                            ->closeOnDateSelection()
                            ->rule('after_or_equal:2020-01-01')
                            ->rules([
                                fn (): \Closure => function (string $attribute, $value, \Closure $fail) {
                                    if ($value === null) {
                                        return;
                                    }
                                    if ((int) Carbon::parse($value)->day !== 1) {
                                        $fail('La fecha de inicio del período fiscal debe ser el día 1 del mes.');
                                    }
                                },
                            ])
                            // Normalización defensiva: si el usuario elige día != 1 (ej. por bypass
                            // de DatePicker), forzamos startOfMonth al persistir.
                            ->dehydrateStateUsing(
                                fn ($state) => $state
                                    ? Carbon::parse($state)->startOfMonth()->toDateString()
                                    : null
                            )
                            // Bloqueo suave: una vez que hay períodos declarados, no permitir
                            // edición del inicio (protege declaraciones SAR ya presentadas).
                            // El super-admin puede alterar por BD si hay una causa legítima.
                            ->disabled(fn (): bool =>
                                FiscalPeriod::whereNotNull('declared_at')->exists()
                            )
                            ->dehydrated(), // persistir aunque esté disabled
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $settings = CompanySetting::current();
        $settings->update($data);

        Notification::make()
            ->title('Configuración guardada')
            ->body('Los datos de la empresa se han actualizado correctamente.')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Guardar Cambios')
                ->icon('heroicon-o-check')
                ->action('save')
                ->color('primary'),
        ];
    }
}
