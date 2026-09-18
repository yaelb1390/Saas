/**
 * FullCalendar, recortado a lo que hace falta para el calendario de alquiler.
 *
 * Mismo motivo que `ag-grid.js`: importaciones nombradas y estáticas, para que el empaquetador se
 * quede solo con lo alcanzable. Mes, semana, día, lista, arrastrar/redimensionar y clic sobre un
 * evento — nada de «Resource Timeline»: esa vista es de la edición Premium de FullCalendar (exige
 * licencia comercial) y la vista «Vehículos» de esta pantalla se construyó aparte, a mano, con las
 * franjas por vehículo (ver `calendar.blade.php`), precisamente para no depender de eso.
 */
import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import listPlugin from '@fullcalendar/list';
import interactionPlugin from '@fullcalendar/interaction';
import esLocale from '@fullcalendar/core/locales/es';

export { Calendar, dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin, esLocale };
