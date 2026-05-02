<?php

use App\Http\Controllers\CashSessionPrintController;
use App\Http\Controllers\CreditNotePrintController;
use App\Http\Controllers\CreditNoteVerificationController;
use App\Http\Controllers\InvoicePrintController;
use App\Http\Controllers\InvoiceVerificationController;
use App\Http\Controllers\IsvDeclarationPrintController;
use App\Http\Controllers\RepairPublicTrackingController;
use App\Http\Controllers\RepairQuotationPrintController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

/*
|--------------------------------------------------------------------------
| Factura — Vista HTML para impresión (autenticada)
|--------------------------------------------------------------------------
|
| GET /invoices/{invoice}
|
| Route model binding por id (columna PK). Se usa dentro del panel admin
| para que un usuario autenticado vea e imprima la factura desde el boton
| "Ver / Imprimir" del Filament Resource.
|
| Autorizacion: Gate + InvoicePolicy@view (dentro del controller).
| El layout usa window.print() — genera PDF nativo del navegador en desktop
| y habilita el share sheet nativo (iOS/Android) para enviar por WhatsApp,
| email, etc. Sin dependencias de Node (compatible con Cloud Hosting).
|
*/
Route::get('/invoices/{invoice}', InvoicePrintController::class)
    ->middleware('auth')
    ->name('invoices.print');

/*
|--------------------------------------------------------------------------
| Factura — Verificacion Publica por QR
|--------------------------------------------------------------------------
|
| GET /facturas/verificar/{hash}
|
| Lookup manual por integrity_hash (SHA-256), NO por id. Esto evita
| enumeracion secuencial de facturas desde la URL publica y cumple con
| el principio de QR autenticable del Acuerdo 481-2017 SAR.
|
| Sin middleware auth: cualquiera con el codigo QR puede verificar.
| La vista muestra banner VALIDA/ANULADA + watermark + hash de integridad.
|
*/
Route::get('/facturas/verificar/{hash}', InvoiceVerificationController::class)
    ->name('invoices.verify')
    ->where('hash', '[a-f0-9]{64}'); // SHA-256 siempre 64 hex chars

/*
|--------------------------------------------------------------------------
| Nota de Credito — Vista HTML para impresion (autenticada)
|--------------------------------------------------------------------------
|
| GET /credit-notes/{creditNote}
|
| Simetrica a invoices.print. Route model binding por id (PK), autorizacion
| via CreditNotePolicy@view dentro del controller. Usada por el boton
| "Ver / Imprimir" del CreditNoteResource (F5).
|
*/
Route::get('/credit-notes/{creditNote}', CreditNotePrintController::class)
    ->middleware('auth')
    ->name('credit-notes.print');

/*
|--------------------------------------------------------------------------
| Nota de Credito — Verificacion Publica por QR
|--------------------------------------------------------------------------
|
| GET /notas-credito/verificar/{hash}
|
| Lookup manual por integrity_hash (SHA-256), simetrico a invoices.verify.
| URL en espanol para consistencia con la ruta publica de facturas
| (/facturas/verificar/...) — el usuario final que escanea el QR nunca
| debe ver paths tecnicos en ingles.
|
| El prefijo 'notas-credito/verificar' debe coincidir con la constante
| VERIFY_PATH_PREFIX en CreditNotePrintService — cualquier cambio aqui
| invalidaria los QR ya impresos, por lo que esta fuertemente acoplado
| y documentado en ambos lados.
|
*/
Route::get('/notas-credito/verificar/{hash}', CreditNoteVerificationController::class)
    ->name('credit-notes.verify')
    ->where('hash', '[a-f0-9]{64}');

/*
|--------------------------------------------------------------------------
| Sesion de Caja — Hoja de cierre para impresion (autenticada)
|--------------------------------------------------------------------------
|
| GET /cash-sessions/{cashSession}
|
| Hoja fisica del cierre de caja — se imprime al cerrar la sesion para que
| el cajero (y el autorizador, si hubo descuadre) firmen el cuadre. Mismo
| patron que invoices.print y credit-notes.print: sin dependencias de Node,
| 100% navegador (window.print() desde la Blade).
|
| Autorizacion: CashSessionPolicy@view dentro del controller.
|
| Una sesion abierta tambien puede imprimirse como "corte parcial" — util
| cuando un supervisor quiere auditar el estado a mitad de dia sin cerrar.
| La Blade muestra un watermark "SESION ABIERTA" en ese caso.
|
*/
Route::get('/cash-sessions/{cashSession}', CashSessionPrintController::class)
    ->middleware('auth')
    ->name('cash-sessions.print');

/*
|--------------------------------------------------------------------------
| Declaración ISV Mensual — Hoja de trabajo imprimible (autenticada)
|--------------------------------------------------------------------------
|
| GET /declaraciones-isv/{isvMonthlyDeclaration}/imprimir
|
| Hoja imprimible del snapshot `IsvMonthlyDeclaration` — la usa el contador
| como respaldo físico para archivar junto al acuse SIISAR y como referencia
| de captura en el portal SAR (Formulario 201). Route model binding por id
| (PK): el ID del snapshot es la fuente de verdad porque un mismo período
| puede tener múltiples snapshots (original + rectificativas) y necesitamos
| reimprimir cualquiera de ellos individualmente para auditoría.
|
| Autorización: FiscalPeriodPolicy@view sobre el período del snapshot
| (reutilizada dentro del controller — el snapshot no tiene Policy propia
| porque es inmutable y no tiene CRUD).
|
| Mismo patrón window.print() que invoices.print / cash-sessions.print: sin
| Node, sin Browsershot. Compatible con Hostinger Cloud Hosting.
|
*/
Route::get('/declaraciones-isv/{isvMonthlyDeclaration}/imprimir', IsvDeclarationPrintController::class)
    ->middleware('auth')
    ->name('isv-declarations.print');

/*
|--------------------------------------------------------------------------
| Reparación — Recibo Interno de Cotización (acceso por qr_token)
|--------------------------------------------------------------------------
|
| GET /repairs/quotation/{repair:qr_token}
|
| Route model binding por `qr_token` (UUID), NO por id. El token actúa
| como "secreto compartido" entre Diproma y el cliente:
|
|   - Staff autenticado: puede ver cualquier reparación, accede vía
|     "Imprimir cotización" en el panel.
|   - Cliente final: ve SU reparación escaneando el QR del recibo físico
|     que se le entregó al recibir el equipo. Sin login.
|
| El controlador rechaza acceso público (404) a reparaciones Anuladas.
| Las demás (incluso terminales como Entregada, Rechazada) son visibles
| para el cliente — sirven como respaldo histórico.
|
| Mismo patrón window.print() que invoices.print: sin Node, sin Browsershot.
|
*/
Route::get('/repairs/quotation/{repair:qr_token}', RepairQuotationPrintController::class)
    ->name('repairs.quotation.print');

/*
|--------------------------------------------------------------------------
| Reparación — Vista pública de tracking (acceso por QR del cliente)
|--------------------------------------------------------------------------
|
| GET /r/{repair:qr_token}
|
| URL corta codificada en el QR del recibo de cotización. Cuando el cliente
| escanea el QR desde su celular, aterriza en esta vista de tracking — NO
| en el recibo imprimible. Razón: el cliente desde móvil quiere ver estado
| + fotos + total, no necesariamente imprimir.
|
| Sin login: el `qr_token` UUID es secreto compartido. Quien tiene el token
| puede ver SU reparación, no las de otros. Reparaciones Anuladas se ocultan
| al público (404 si no autenticado) — solo el staff las consulta.
|
| Para imprimir el recibo, hay un botón en esta vista que lleva a la ruta
| `repairs.quotation.print`.
|
*/
Route::get('/r/{repair:qr_token}', RepairPublicTrackingController::class)
    ->name('repairs.public.show');
