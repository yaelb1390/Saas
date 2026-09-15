/**
 * FullCalendar, recortado a lo que hace falta para el calendario de alquiler.
 *
 * Mismo motivo que `ag-grid.js`: importaciones nombradas y estáticas, para que el empaquetador se
 * quede solo con lo alcanzable. Solo dos plugins —vista de mes y clic sobre un evento—, nada de vista
 * semanal ni arrastrar para mover fechas, que no hace falta en la pantalla de solo lectura del panel.
 */
import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import esLocale from '@fullcalendar/core/locales/es';

export { Calendar, dayGridPlugin, interactionPlugin, esLocale };
