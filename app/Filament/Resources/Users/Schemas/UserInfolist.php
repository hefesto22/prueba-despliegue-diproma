<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Información Personal')
                    ->icon('heroicon-o-user')
                    ->schema([
                        Grid::make(3)->schema([
                            ImageEntry::make('avatar_url')
                                ->label('Foto de perfil')
                                ->circular()
                                ->defaultImageUrl(fn ($record) => 'https://ui-avatars.com/api/?name=' . urlencode($record->name) . '&color=FFFFFF&background=F59E0B'),
                            TextEntry::make('name')
                                ->label('Nombre completo'),
                            TextEntry::make('email')
                                ->label('Correo electrónico')
                                ->icon('heroicon-o-envelope'),
                        ]),
                        Grid::make(2)->schema([
                            TextEntry::make('phone')
                                ->label('Teléfono')
                                ->icon('heroicon-o-phone')
                                ->placeholder('No registrado'),
                            TextEntry::make('is_active')
                                ->label('Estado')
                                ->badge()
                                ->formatStateUsing(fn (bool $state): string => $state ? 'Activo' : 'Inactivo')
                                ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
                        ]),
                    ]),

                Section::make('Roles y Permisos')
                    ->icon('heroicon-o-shield-check')
                    ->schema([
                        TextEntry::make('roles.name')
                            ->label('Roles asignados')
                            ->badge()
                            ->color('primary')
                            ->separator(',')
                            ->placeholder('Sin roles asignados'),
                    ]),

                Section::make('Sucursal de Trabajo')
                    ->icon('heroicon-o-building-storefront')
                    ->schema([
                        TextEntry::make('defaultEstablishment.name')
                            ->label('Sucursal activa')
                            ->badge()
                            ->color('gray')
                            ->icon('heroicon-o-building-storefront')
                            ->placeholder('Sin asignar — usa la matriz como fallback'),
                    ]),

                Section::make('Último Acceso')
                    ->icon('heroicon-o-arrow-right-on-rectangle')
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('last_login_at')
                                ->label('Último inicio de sesión')
                                ->dateTime('d/m/Y H:i')
                                ->placeholder('Nunca ha iniciado sesión'),
                            TextEntry::make('last_login_ip')
                                ->label('IP del último acceso')
                                ->placeholder('Sin registro'),
                        ]),
                    ]),

                Section::make('Auditoría')
                    ->icon('heroicon-o-information-circle')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('email_verified_at')
                                ->label('Email verificado')
                                ->dateTime('d/m/Y H:i')
                                ->placeholder('Sin verificar')
                                ->icon('heroicon-o-check-badge'),
                            TextEntry::make('created_at')
                                ->label('Creado el')
                                ->dateTime('d/m/Y H:i'),
                            TextEntry::make('updated_at')
                                ->label('Actualizado el')
                                ->dateTime('d/m/Y H:i'),
                        ]),
                        Grid::make(3)->schema([
                            TextEntry::make('createdBy.name')
                                ->label('Creado por')
                                ->placeholder('Sistema')
                                ->icon('heroicon-o-user'),
                            TextEntry::make('updatedBy.name')
                                ->label('Actualizado por')
                                ->placeholder('Sistema')
                                ->icon('heroicon-o-user'),
                            TextEntry::make('deletedBy.name')
                                ->label('Eliminado por')
                                ->placeholder('-')
                                ->icon('heroicon-o-user'),
                        ]),
                    ]),
            ]);
    }
}