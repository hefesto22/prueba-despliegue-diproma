# Preguntas para el contador de Diproma

**Fecha:** Abril 2026
**Contexto:** Estamos cerrando el sistema fiscal y queremos confirmar dos cosas para no construir funcionalidad innecesaria. Ninguna pregunta es urgente, son para planificar las próximas semanas.

---

## Bloque 1 — Declaración mensual de ISV (Formulario 210)

Hoy el sistema ya emite el **Libro de Compras** y el **Libro de Ventas** del SAR en Excel. Lo que falta saber es si querés que el sistema te entregue también el Formulario 210 ya consolidado, o si preferís seguir armándolo a mano desde los libros.

1. **¿Cómo presentás hoy la declaración mensual de ISV?**
   - [ ] Tecleo los números directamente en el portal del SAR
   - [ ] Subo un archivo / formulario al portal del SAR
   - [ ] Otro: _______________

2. **Si el sistema te entregara los números del Formulario 210 ya calculados, ¿qué formato te serviría más?**
   - [ ] PDF imprimible con los campos llenos (para tener respaldo físico)
   - [ ] Excel con los números organizados como el formulario (para copiar/pegar al portal)
   - [ ] Solo un resumen en pantalla con los totales (yo los tecleo en el portal)
   - [ ] No lo necesito, los libros ya me alcanzan

3. **Aparte del crédito fiscal del mes (compras) y débito fiscal (ventas), ¿hay otros campos del 210 que llenes manualmente?** Por ejemplo:
   - Saldo a favor de meses anteriores
   - Compensaciones
   - Retenciones de ISV recibidas (si algún cliente les retiene)
   - Otros créditos / ajustes
   - _______________

---

## Bloque 2 — Retenciones de ISR

Esta pregunta define si construimos o no un módulo de retenciones. Solo aplica si Diproma está designada como **agente retenedor del ISR** por el SAR.

4. **¿Diproma está registrada ante el SAR como agente retenedor del ISR?**
   - [ ] Sí, somos agentes retenedores
   - [ ] No, no somos agentes retenedores
   - [ ] No estoy seguro, lo verifico

5. **Si SÍ son agentes retenedores:**
   a. ¿Qué porcentaje retienen y a qué tipo de proveedores?
      Ejemplo: "12.5% a profesionales independientes con facturas >X lempiras"
      Respuesta: _______________

   b. ¿Cómo emiten hoy la **constancia de retención** al proveedor?
      - [ ] Plantilla de Word/Excel manual
      - [ ] Sistema externo
      - [ ] No emiten constancia formal
      - [ ] Otro: _______________

   c. ¿Qué formulario usan para declarar las retenciones al SAR y con qué frecuencia?
      Ejemplo: "Formulario 216 mensual"
      Respuesta: _______________

   d. ¿Cuántas retenciones aproximadas hacen al mes?
      - [ ] Menos de 5
      - [ ] Entre 5 y 20
      - [ ] Más de 20

---

## Bloque 3 — Otras obligaciones (opcional)

Esto es para detectar si hay algo grande que no estamos viendo y vale la pena construir antes que las dos opciones de arriba.

6. **¿Hay algo que hoy hagas a mano para Diproma que te consuma mucho tiempo y que un sistema podría resolver?** Por ejemplo:
   - Planillas de empleados (IHSS / RAP / ISR retenido a empleados)
   - Inventario de fin de año para ISR anual
   - Conciliación bancaria
   - Reportes para la gerencia
   - Otro: _______________

7. **¿Hay alguna obligación nueva del SAR que esté entrando en vigor que tengamos que prepararnos?**
   Por ejemplo, factura electrónica obligatoria para cierto rango de ingresos, nuevos formularios, cambios en tasas.
   Respuesta: _______________

---

## Lo que NO te estoy preguntando (porque ya está cubierto en el sistema)

Para que sepas qué tenemos resuelto y no tengas que comentarlo:

- ✅ Emisión de facturas con CAI vigente, control de rangos, alertas antes de vencimiento, failover automático cuando vence
- ✅ Notas de Crédito (documento SAR tipo 04)
- ✅ Recibos Internos para compras informales (mercados, productores sin RTN) — no entran al Libro de Compras SAR
- ✅ Períodos fiscales con declaración / reapertura para rectificativas
- ✅ Libros de Ventas y Compras del SAR (Excel listo para presentar)
- ✅ Caja diaria con apertura, cierre, conteo físico, gastos menores e impresión
- ✅ Multi-sucursal a nivel operativo (sucursal por venta/compra/movimiento)

---

**Gracias.** Con tus respuestas a los Bloques 1 y 2 puedo definir el alcance de las próximas semanas. El Bloque 3 es bonus si tenés tiempo.
