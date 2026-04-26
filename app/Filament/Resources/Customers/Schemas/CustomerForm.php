<?php

namespace App\Filament\Resources\Customers\Schemas;

use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos del Cliente')
                ->icon('heroicon-o-user')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('rtn')
                        ->label('RTN')
                        ->placeholder('0801-1999-123456')
                        ->maxLength(20)
                        ->unique(ignoreRecord: true),
                    TextInput::make('phone')
                        ->label('Teléfono')
                        ->tel()
                        ->maxLength(20),
                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->maxLength(255),
                    Textarea::make('address')
                        ->label('Dirección')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
            Section::make('Estado')
                ->schema([
                    Toggle::make('is_active')
                        ->label('Activo')
                        ->default(true),
                    Textarea::make('notes')
                        ->label('Notas')
                        ->rows(2),
                ]),
        ]);
    }
}
