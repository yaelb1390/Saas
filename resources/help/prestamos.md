---
title: Préstamos, cuotas y cobros
module: loans
permission: loans.view
keywords: prestamo, prestamos, cuota, cuotas, cobro, abono, mora, interes, amortizacion, saldo, cartera, vencida
related: solicitudes-de-prestamo, finanzas
route: panel.loans
---

La cartera de préstamos con sus cuotas, cobros y mora.

## Crear un préstamo

Escribes el capital, el interés (como tasa o como monto directo, que manda sobre la tasa), el número de cuotas y cada cuánto se paga: diario, semanal, quincenal o mensual. El sistema calcula el total y la cuota, y genera la tabla de amortización.

El capital sale de la caja en ese momento.

## Registrar un abono

El abono se reparte entre las cuotas **más antiguas sin saldar**, cubriendo también su mora. Cuando el saldo llega a cero, el préstamo queda saldado.

De cada cobro puedes imprimir un recibo o sacarlo en PDF de 80 mm.

## Mora

Se pone cuota a cuota, con la tasa de mora del préstamo como referencia. Sube el saldo del préstamo.

## Vencidos

El listado tiene un filtro de préstamos con cuotas vencidas, y el resumen de arriba te dice cuánto hay en mora.

## Anular

Solo si el préstamo **no tiene ningún cobro** registrado.
