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
    CellStyleModule, // pintar en rojo un margen negativo
    ClientSideRowModelModule, // las filas viven en el navegador: ordena y filtra al vuelo
    ColumnAutoSizeModule,
    createGrid,
    CsvExportModule, // exportar a CSV; el de Excel es de pago
    LocaleModule, // los textos de la rejilla, en español
    ModuleRegistry,
    NumberFilterModule,
    PaginationModule, // el pie de «Mostrando 1 a 15 de 47»
    TextFilterModule,
    themeQuartz,
    ValidationModule, // dice en consola QUÉ módulo falta si algo no está registrado
} from 'ag-grid-community';

ModuleRegistry.registerModules([
    ClientSideRowModelModule,
    TextFilterModule,
    NumberFilterModule,
    PaginationModule,
    CsvExportModule,
    CellStyleModule,
    ColumnAutoSizeModule,
    LocaleModule,
    ValidationModule,
]);

export { createGrid, themeQuartz };
