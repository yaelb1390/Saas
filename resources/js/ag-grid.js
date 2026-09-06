/**
 * AG Grid, recortado a lo que de verdad se usa.
 *
 * ESTE FICHERO EXISTE PARA QUE ROLLUP PUEDA PODAR. Al principio el cargador hacía
 * `const m = await import('ag-grid-community')` y leía `m.LoQueSea`: con el espacio de nombres
 * entero en una variable, el empaquetador no puede saber qué sobra y se lleva el paquete completo.
 * Lo medí y salía el mismo trozo de 1,36 MB por más piezas sueltas que registrara.
 *
 * Con importaciones NOMBRADAS y estáticas sí puede: se queda solo con lo alcanzable. El cargador de
 * `app.js` importa este fichero de forma dinámica, así que el recorte y la carga bajo demanda se
 * suman en vez de estorbarse.
 *
 * Edición Community, MIT y gratuita para uso comercial. Agrupar filas, maestro/detalle y exportar a
 * Excel son de pago y no se usan: sin licencia pintan una marca de agua encima de la tabla.
 */
import {
    CellApiModule, // `startEditingCell`: al meter una línea, el cursor cae en su cantidad
    CellStyleModule, // pintar en rojo un margen negativo
    ClientSideRowModelApiModule, // cambiar las filas sin volver a crear la rejilla
    ClientSideRowModelModule, // las filas viven en el navegador: ordena y filtra al vuelo
    ColumnAutoSizeModule,
    createGrid,
    CsvExportModule, // exportar a CSV; el de Excel es de pago
    LocaleModule, // los textos de la rejilla, en español
    ModuleRegistry,
    NumberEditorModule, // cantidad y descuento del ticket
    NumberFilterModule,
    PaginationModule, // el pie de «Mostrando 1 a 15 de 47»
    RowStyleModule, // `rowClass`: el cursor que dice que la fila se puede pulsar
    SelectEditorModule, // el empleado de la línea, que es una lista cerrada
    TextEditorModule, // nº de serie y nota de la línea
    TextFilterModule,
    themeQuartz,
    ValidationModule, // dice en consola QUÉ módulo falta si algo no está registrado
} from 'ag-grid-community';

/*
 * Los EDITORES son de la edición Community, comprobado contra el paquete instalado y no contra la
 * documentación: `TextEditorModule`, `NumberEditorModule` y `SelectEditorModule` existen. Los que no
 * están son `RowGroupingModule` y `ExcelExportModule`, que siguen siendo de pago.
 */
ModuleRegistry.registerModules([
    ClientSideRowModelModule,
    ClientSideRowModelApiModule,
    CellApiModule,
    TextFilterModule,
    NumberFilterModule,
    PaginationModule,
    RowStyleModule,
    CsvExportModule,
    CellStyleModule,
    ColumnAutoSizeModule,
    TextEditorModule,
    NumberEditorModule,
    SelectEditorModule,
    LocaleModule,
    ValidationModule,
]);

export { createGrid, themeQuartz };
