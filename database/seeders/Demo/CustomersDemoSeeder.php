<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Crea clientes de demo realistas para datos históricos.
 *
 * Mix:
 *   - 8 clientes con RTN (PYMES, profesionales, comercios — los que requieren
 *     factura con datos completos para deducir ISV).
 *   - 4 clientes sin RTN (consumidores recurrentes que dejaron datos para
 *     llamarles si dejan algo, pero compran como CF).
 *
 * Por qué mezclar ambos:
 *   - El POS de Diproma soporta venta a Consumidor Final sin Customer (mass
 *     market, ~80% de ventas) y venta a Customer registrado (CF recurrente
 *     o con RTN).
 *   - Tener clientes registrados con y sin RTN cubre los dos sub-casos del
 *     "cliente registrado" en los reportes y facilita validar la lógica de
 *     getFormattedRtnAttribute / isConsumidorFinal().
 *
 * Atribución:
 *   - created_by = Sofía (cajera) — los clientes los registra normalmente
 *     el cajero al momento de facturar. Carlos (admin) los actualiza si
 *     hace falta corregir datos.
 *
 * Idempotente:
 *   - Para los con RTN: firstOrCreate por rtn (único en negocio).
 *   - Para los sin RTN: firstOrCreate por nombre + teléfono.
 */
class CustomersDemoSeeder extends Seeder
{
    public function run(): void
    {
        $sofia = User::where('email', 'sofia.lopez@diproma.hn')->firstOrFail();

        $clientesConRtn = [
            [
                'name' => 'Ferretería La Solución',
                'rtn' => '08019988776655',
                'phone' => '9988-1212',
                'email' => 'compras@ferresolucion.hn',
                'address' => 'Bo. Las Acacias, San Pedro Sula',
            ],
            [
                'name' => 'Constructora Vega',
                'rtn' => '08019977665544',
                'phone' => '9988-2323',
                'email' => 'admin@constructoravega.hn',
                'address' => 'Col. Trejo, casa #45',
            ],
            [
                'name' => 'Talleres Mejía',
                'rtn' => '08019966554433',
                'phone' => '9988-3434',
                'email' => 'taller.mejia@gmail.com',
                'address' => 'Bo. Río de Piedras, 6ta avenida',
            ],
            [
                'name' => 'Distribuidora La Pradera',
                'rtn' => '08019955443322',
                'phone' => '9988-4545',
                'email' => 'pradera@correo.hn',
                'address' => 'Col. La Pradera, calle principal',
            ],
            [
                'name' => 'Lic. Roberto Aguilar',
                'rtn' => '08019944332211',
                'phone' => '9988-5656',
                'email' => 'raguilar.cpa@gmail.com',
                'address' => 'Edificio Plaza, oficina 3B',
            ],
            [
                'name' => 'Ing. Patricia Domínguez',
                'rtn' => '08019933221100',
                'phone' => '9988-6767',
                'email' => 'pdominguez@ingenieros.hn',
                'address' => 'Col. Juan Lindo',
            ],
            [
                'name' => 'Comercial Doña Ofelia',
                'rtn' => '08019922110099',
                'phone' => '9988-7878',
                'email' => null,
                'address' => 'Mercado Medina Concepción, local 14',
            ],
            [
                'name' => 'Servicios Técnicos Rivera',
                'rtn' => '08019911009988',
                'phone' => '9988-8989',
                'email' => 'srivera.tecnico@gmail.com',
                'address' => 'Bo. Lempira, calle 5',
            ],
        ];

        $clientesSinRtn = [
            [
                'name' => 'Manuel Argueta',
                'phone' => '9966-1010',
                'email' => null,
                'address' => 'Col. Trejo',
            ],
            [
                'name' => 'Karla Bonilla',
                'phone' => '9966-2020',
                'email' => 'karla.bonilla@gmail.com',
                'address' => null,
            ],
            [
                'name' => 'Pedro Hernández',
                'phone' => '9966-3030',
                'email' => null,
                'address' => 'Bo. Suyapa',
            ],
            [
                'name' => 'Gloria Madrid',
                'phone' => '9966-4040',
                'email' => null,
                'address' => 'Col. Las Acacias',
            ],
        ];

        foreach ($clientesConRtn as $data) {
            Customer::firstOrCreate(
                ['rtn' => $data['rtn']],
                array_merge($data, [
                    'is_active' => true,
                    'created_by' => $sofia->id,
                ])
            );
        }

        foreach ($clientesSinRtn as $data) {
            Customer::firstOrCreate(
                ['name' => $data['name'], 'phone' => $data['phone']],
                array_merge($data, [
                    'rtn' => null,
                    'is_active' => true,
                    'created_by' => $sofia->id,
                ])
            );
        }

        $this->command?->info(sprintf(
            'Clientes demo creados: %d con RTN + %d sin RTN',
            count($clientesConRtn),
            count($clientesSinRtn),
        ));
    }
}
