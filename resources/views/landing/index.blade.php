{{--
    La presentación del producto.

    Es la primera pantalla de quien llega desde Instagram o desde un mensaje de WhatsApp, así que
    responde en este orden lo que esa persona se pregunta: qué es, si sirve para SU negocio, qué
    incluye y cuánto cuesta. El botón de probarlo aparece arriba y abajo: quien se convence en la
    primera línea no debería tener que buscar dónde empezar.
--}}
<!DOCTYPE html>
<html lang="es" class="h-full scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BM Business OS · El sistema para administrar tu negocio</title>

    {{-- Para cuando el enlace se comparte por WhatsApp, que es como va a viajar casi siempre. --}}
    <meta name="description" content="Ventas, inventario, facturación con NCF y WhatsApp en un solo lugar. Pensado para negocios de República Dominicana.">
    <meta property="og:title" content="BM Business OS">
    <meta property="og:description" content="Ventas, inventario, facturación con NCF y WhatsApp en un solo lugar.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    @if (file_exists(public_path('images/bm-mark.png')))
        <meta property="og:image" content="{{ asset('images/bm-mark.png') }}">
        <link rel="icon" type="image/png" href="{{ asset('images/bm-mark.png') }}">
    @endif

    {{-- Cargamos CSS y JS para activar Alpine.js --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-[#08090f] text-slate-200 antialiased min-h-screen relative overflow-x-hidden">

@php
    $logo = public_path('images/bm-logo.png');
    $enlaceWhatsapp = 'https://wa.me/'.$whatsapp.'?text='.rawurlencode('Hola, quiero información sobre BM Business.');
@endphp

<!-- Orbes de Luz de Fondo (Efecto WOW) -->
<div class="absolute top-[-10%] left-[-10%] w-[50%] h-[50%] rounded-full bg-indigo-900/20 blur-[120px] pointer-events-none"></div>
<div class="absolute top-[40%] right-[-10%] w-[45%] h-[45%] rounded-full bg-violet-900/15 blur-[120px] pointer-events-none"></div>
<div class="absolute bottom-[-5%] left-[20%] w-[50%] h-[50%] rounded-full bg-emerald-950/20 blur-[130px] pointer-events-none"></div>

<div class="relative z-10 max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-4 sm:py-8">

    {{-- ============ NAVEGACIÓN SUPERIOR ============ --}}
    <nav class="flex items-center justify-between border-b border-white/[0.06] pb-6 mb-12 sm:mb-16">
        <a href="/" class="flex items-center gap-2">
            @if (file_exists($logo))
                <img src="{{ asset('images/bm-logo.png') }}?v={{ filemtime($logo) }}" alt="BM Business OS" class="h-8 sm:h-10 w-auto">
            @else
                <span class="text-xl sm:text-2xl font-black tracking-tight text-white">BM <span class="text-indigo-400">Business OS</span></span>
            @endif
        </a>
        <div class="flex items-center gap-3 sm:gap-4">
            <a href="{{ route('login') }}" class="text-sm font-semibold text-slate-300 hover:text-white transition duration-200">
                Entrar
            </a>
            <a href="{{ route('register.form') }}" class="inline-flex items-center justify-center px-4 py-2 text-xs sm:text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-500 rounded-xl transition duration-200 shadow-md shadow-indigo-600/20">
                Probar Gratis
            </a>
        </div>
    </nav>

    {{-- ============ HERO SECTION ============ --}}
    <header class="text-center max-w-4xl mx-auto mb-16 sm:mb-24">
        <span class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-semibold bg-indigo-500/10 text-indigo-300 border border-indigo-500/20 mb-6 animate-pulse">
            <span class="w-2 h-2 rounded-full bg-indigo-400"></span>
            Listo para República Dominicana (DGII & NCF)
        </span>
        <h1 class="text-4xl sm:text-6xl font-black tracking-tight text-transparent bg-clip-text bg-gradient-to-r from-white via-slate-100 to-indigo-200 leading-[1.1] mb-6">
            Todo tu negocio, <br class="hidden sm:inline">en un solo lugar
        </h1>
        <p class="text-base sm:text-xl text-slate-400 max-w-2xl mx-auto leading-relaxed mb-8">
            Cobra rápido, controla tu inventario en tiempo real, emite facturas con NCF y automatiza tus ventas por WhatsApp en una sola plataforma premium.
        </p>

        <div class="flex flex-col sm:flex-row items-center justify-center gap-4 max-w-md mx-auto mb-6">
            <a href="{{ route('register.form') }}" class="w-full sm:w-auto inline-flex items-center justify-center px-8 py-3.5 rounded-2xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-base transition-all duration-300 transform hover:-translate-y-0.5 shadow-lg shadow-indigo-600/30">
                Pruébalo {{ $diasPrueba }} días gratis
            </a>
            <a href="{{ $enlaceWhatsapp }}" target="_blank" rel="noopener" class="w-full sm:w-auto inline-flex items-center justify-center px-8 py-3.5 rounded-2xl bg-emerald-600/90 hover:bg-emerald-600 text-white font-bold text-base transition-all duration-300 transform hover:-translate-y-0.5 shadow-lg shadow-emerald-600/20 gap-2">
                <!-- Icono WhatsApp -->
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.148-.669-1.611-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.71.306 1.263.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884a9.82 9.82 0 0 1 6.988 2.896 9.83 9.83 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.8 11.8 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.9 11.9 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.82 11.82 0 0 0 20.464 3.488"/>
                </svg>
                WhatsApp Directo
            </a>
        </div>
        <p class="text-xs text-slate-500 font-medium">
            Acceso inmediato • Sin tarjeta de crédito requerida
        </p>
    </header>

    {{-- ============ MOCKUP INTERACTIVO (WOW FACTOR) ============ --}}
    <section class="max-w-5xl mx-auto mb-20 sm:mb-32" x-data="{ activeTab: 'pos' }">
        <div class="text-center mb-8">
            <h2 class="text-xs font-bold uppercase tracking-widest text-indigo-400 mb-2">BM en Acción</h2>
            <p class="text-xl sm:text-2xl font-bold text-white">Haz clic y mira cómo funciona</p>
        </div>

        {{-- Contenedor del Mockup --}}
        <div class="grid grid-cols-1 lg:grid-cols-[14rem_1fr] gap-6 bg-[#0f1224]/60 border border-white/[0.06] rounded-3xl p-4 sm:p-6 backdrop-blur-xl shadow-2xl relative">
            
            {{-- Tabs de Navegación Lateral (Mockup Menu) --}}
            <div class="flex flex-row lg:flex-col overflow-x-auto lg:overflow-x-visible gap-2 pb-3 lg:pb-0 border-b lg:border-b-0 lg:border-r border-white/[0.06] pr-0 lg:pr-4 shrink-0">
                <!-- Tab POS -->
                <button @click="activeTab = 'pos'" :class="activeTab === 'pos' ? 'bg-indigo-600 text-white font-bold' : 'text-slate-400 hover:bg-white/[0.03] hover:text-slate-200'" class="flex items-center gap-2.5 px-4 py-3 rounded-xl text-left text-xs sm:text-sm transition-all duration-200 w-full whitespace-nowrap">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z"/>
                    </svg>
                    <span>Venta Rápida (POS)</span>
                </button>
                <!-- Tab WhatsApp -->
                <button @click="activeTab = 'whatsapp'" :class="activeTab === 'whatsapp' ? 'bg-indigo-600 text-white font-bold' : 'text-slate-400 hover:bg-white/[0.03] hover:text-slate-200'" class="flex items-center gap-2.5 px-4 py-3 rounded-xl text-left text-xs sm:text-sm transition-all duration-200 w-full whitespace-nowrap">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z"/>
                    </svg>
                    <span>Ventas WhatsApp IA</span>
                </button>
                <!-- Tab Inventario -->
                <button @click="activeTab = 'inventario'" :class="activeTab === 'inventario' ? 'bg-indigo-600 text-white font-bold' : 'text-slate-400 hover:bg-white/[0.03] hover:text-slate-200'" class="flex items-center gap-2.5 px-4 py-3 rounded-xl text-left text-xs sm:text-sm transition-all duration-200 w-full whitespace-nowrap">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"/>
                    </svg>
                    <span>Inventario & NCF</span>
                </button>
                <!-- Tab Reportes -->
                <button @click="activeTab = 'reportes'" :class="activeTab === 'reportes' ? 'bg-indigo-600 text-white font-bold' : 'text-slate-400 hover:bg-white/[0.03] hover:text-slate-200'" class="flex items-center gap-2.5 px-4 py-3 rounded-xl text-left text-xs sm:text-sm transition-all duration-200 w-full whitespace-nowrap">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 14.25v2.25m3-4.5v4.5m3-6.75v6.75m3-9v9M6 20.25h12A2.25 2.25 0 0 0 20.25 18V6A2.25 2.25 0 0 0 18 3.75H6A2.25 2.25 0 0 0 3.75 6v12A2.25 2.25 0 0 0 6 20.25Z"/>
                    </svg>
                    <span>Reportes & Caja</span>
                </button>
            </div>

            {{-- Área de Visualización de la Pantalla del Mockup --}}
            <div class="bg-[#0b0c16] rounded-2xl border border-white/5 overflow-hidden shadow-2xl min-h-[380px] flex flex-col">
                {{-- Cabecera del Navegador/App Ficticia --}}
                <div class="bg-[#121426] border-b border-white/[0.04] px-4 py-3 flex items-center justify-between">
                    <div class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-full bg-rose-500"></span>
                        <span class="w-3 h-3 rounded-full bg-amber-500"></span>
                        <span class="w-3 h-3 rounded-full bg-emerald-500"></span>
                    </div>
                    <div class="bg-black/30 border border-white/5 rounded-lg px-6 py-0.5 text-[11px] text-slate-400 select-none">
                        bmos.do/panel/redes
                    </div>
                    <div class="w-8"></div>
                </div>

                {{-- Contenidos Dinámicos --}}
                <div class="p-4 sm:p-6 flex-1 flex flex-col justify-between">
                    
                    {{-- 1. PANTALLA POS --}}
                    <div x-show="activeTab === 'pos'" class="flex-1 flex flex-col md:flex-row gap-4" x-cloak>
                        <!-- Grid de Productos -->
                        <div class="flex-1">
                            <p class="text-xs font-bold text-slate-400 uppercase mb-3">Productos Rápidos</p>
                            <div class="grid grid-cols-2 gap-2">
                                <div class="bg-white/[0.02] border border-white/[0.04] p-3 rounded-xl hover:border-indigo-500/30 transition-all select-none">
                                    <p class="text-sm font-semibold text-white">Combo Familiar</p>
                                    <p class="text-xs text-indigo-400 font-bold mt-1">RD$ 1,200.00</p>
                                </div>
                                <div class="bg-white/[0.02] border border-white/[0.04] p-3 rounded-xl hover:border-indigo-500/30 transition-all select-none">
                                    <p class="text-sm font-semibold text-white">Batida de Guineo</p>
                                    <p class="text-xs text-indigo-400 font-bold mt-1">RD$ 150.00</p>
                                </div>
                                <div class="bg-white/[0.02] border border-white/[0.04] p-3 rounded-xl hover:border-indigo-500/30 transition-all select-none">
                                    <p class="text-sm font-semibold text-white">Helado Copa</p>
                                    <p class="text-xs text-indigo-400 font-bold mt-1">RD$ 210.00</p>
                                </div>
                                <div class="bg-white/[0.02] border border-white/[0.04] p-3 rounded-xl hover:border-indigo-500/30 transition-all select-none">
                                    <p class="text-sm font-semibold text-white">Café + Empanada</p>
                                    <p class="text-xs text-indigo-400 font-bold mt-1">RD$ 180.00</p>
                                </div>
                            </div>
                        </div>
                        <!-- Ticket de Venta -->
                        <div class="w-full md:w-64 bg-[#121428] border border-white/[0.06] rounded-xl p-4 flex flex-col justify-between">
                            <div>
                                <div class="flex justify-between items-center border-b border-white/[0.06] pb-2 mb-3">
                                    <p class="text-xs font-bold text-white uppercase">Ticket #0124</p>
                                    <span class="text-[10px] px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">Caja Abierta</span>
                                </div>
                                <div class="space-y-2 text-xs">
                                    <div class="flex justify-between text-slate-300">
                                        <span>1x Combo Familiar</span>
                                        <span>RD$ 1,200.00</span>
                                    </div>
                                    <div class="flex justify-between text-slate-300">
                                        <span>2x Batida de Guineo</span>
                                        <span>RD$ 300.00</span>
                                    </div>
                                </div>
                            </div>
                            <div class="border-t border-white/[0.06] pt-3 mt-4 space-y-1.5 text-xs">
                                <div class="flex justify-between text-slate-400">
                                    <span>Subtotal</span>
                                    <span>RD$ 1,500.00</span>
                                </div>
                                <div class="flex justify-between text-slate-400">
                                    <span>ITBIS (18%)</span>
                                    <span>RD$ 270.00</span>
                                </div>
                                <div class="flex justify-between text-base font-bold text-white pt-1">
                                    <span>Total</span>
                                    <span class="text-indigo-400">RD$ 1,770.00</span>
                                </div>
                                <button type="button" class="w-full bg-indigo-600 text-white font-bold py-2 rounded-lg mt-3 text-xs shadow-md shadow-indigo-600/20 cursor-default">
                                    Registrar & Cobrar
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- 2. PANTALLA WHATSAPP IA --}}
                    <div x-show="activeTab === 'whatsapp'" class="flex-1 flex flex-col bg-[#0b0c16] rounded-xl border border-white/[0.04] overflow-hidden" x-cloak>
                        <!-- Cabecera de Chat -->
                        <div class="bg-[#121428] px-4 py-2 border-b border-white/[0.04] flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <div class="w-6 h-6 rounded-full bg-emerald-600 flex items-center justify-center font-bold text-[10px] text-white">Y</div>
                                <div>
                                    <p class="text-xs font-bold text-white">Yasmely (Instagram/WhatsApp)</p>
                                    <p class="text-[9px] text-emerald-400">En línea • Bot Asistente</p>
                                </div>
                            </div>
                        </div>
                        <!-- Cuerpo de Mensajes -->
                        <div class="p-4 space-y-3 flex-1 overflow-y-auto text-xs max-h-[220px]">
                            <div class="flex justify-start">
                                <div class="bg-slate-800 text-slate-100 rounded-2xl rounded-tl-none px-3.5 py-2 max-w-[80%]">
                                    hola! tienen batidas de guineo y combo familiar?
                                </div>
                            </div>
                            <div class="flex justify-end">
                                <div class="bg-indigo-600/90 text-white rounded-2xl rounded-tr-none px-3.5 py-2 max-w-[80%]">
                                    <div class="flex items-center gap-1.5 mb-1 text-[9px] text-indigo-200 font-bold uppercase tracking-wider">
                                        <span class="bg-indigo-400/20 px-1 py-0.5 rounded text-[8px]">IA</span>
                                        BM Bot
                                    </div>
                                    ¡Hola Yasmely! Sí, tenemos el **Combo Familiar** a RD$1,200 y la **Batida de Guineo** a RD$150. ¿Deseas que preparemos tu pedido para envío?
                                </div>
                            </div>
                            <div class="flex justify-start">
                                <div class="bg-slate-800 text-slate-100 rounded-2xl rounded-tl-none px-3.5 py-2 max-w-[80%]">
                                    Sii y envíame la factura por favor
                                </div>
                            </div>
                            <div class="flex justify-end">
                                <div class="bg-indigo-600/90 text-white rounded-2xl rounded-tr-none px-3.5 py-2 max-w-[80%]">
                                    ¡Listo! Generamos tu pedido #0124. Puedes ver tu factura con NCF y seguir la entrega aquí: **bmos.do/portal/yasmely**
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- 3. PANTALLA INVENTARIO --}}
                    <div x-show="activeTab === 'inventario'" class="flex-1 overflow-x-auto" x-cloak>
                        <p class="text-xs font-bold text-slate-400 uppercase mb-3">Control de Existencias & NCF</p>
                        <table class="w-full text-left text-xs border-collapse">
                            <thead>
                                <tr class="border-b border-white/[0.06] text-slate-400">
                                    <th class="py-2.5 font-bold">Producto</th>
                                    <th class="py-2.5 font-bold">Stock</th>
                                    <th class="py-2.5 font-bold text-right">Precio</th>
                                    <th class="py-2.5 font-bold text-center">Estado</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.04]">
                                <tr>
                                    <td class="py-3 font-medium text-white">Combo Familiar</td>
                                    <td class="py-3 text-slate-300">42 uds</td>
                                    <td class="py-3 text-right text-indigo-400 font-bold">RD$ 1,200.00</td>
                                    <td class="py-3 text-center">
                                        <span class="inline-block px-2 py-0.5 rounded text-[10px] bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">Disponible</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="py-3 font-medium text-white">Batida de Guineo</td>
                                    <td class="py-3 text-slate-300">3 uds</td>
                                    <td class="py-3 text-right text-indigo-400 font-bold">RD$ 150.00</td>
                                    <td class="py-3 text-center">
                                        <span class="inline-block px-2 py-0.5 rounded text-[10px] bg-amber-500/10 text-amber-400 border border-amber-500/20">Bajo Stock</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="py-3 font-medium text-white">Helado Copa</td>
                                    <td class="py-3 text-slate-300">120 uds</td>
                                    <td class="py-3 text-right text-indigo-400 font-bold">RD$ 210.00</td>
                                    <td class="py-3 text-center">
                                        <span class="inline-block px-2 py-0.5 rounded text-[10px] bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">Disponible</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    {{-- 4. PANTALLA REPORTES --}}
                    <div x-show="activeTab === 'reportes'" class="flex-1 flex flex-col gap-4" x-cloak>
                        <!-- Tarjetas de Métricas -->
                        <div class="grid grid-cols-3 gap-3">
                            <div class="bg-white/[0.02] border border-white/[0.04] p-3 rounded-xl text-center">
                                <p class="text-[10px] text-slate-400 font-medium uppercase">Ventas de Hoy</p>
                                <p class="text-sm sm:text-base font-bold text-white mt-1">RD$ 18,450</p>
                                <span class="text-[9px] text-emerald-400 font-bold">+14.2%</span>
                            </div>
                            <div class="bg-white/[0.02] border border-white/[0.04] p-3 rounded-xl text-center">
                                <p class="text-[10px] text-slate-400 font-medium uppercase">Ganancia Estimada</p>
                                <p class="text-sm sm:text-base font-bold text-white mt-1">RD$ 6,520</p>
                                <span class="text-[9px] text-indigo-400 font-bold">35.3% Margen</span>
                            </div>
                            <div class="bg-white/[0.02] border border-white/[0.04] p-3 rounded-xl text-center">
                                <p class="text-[10px] text-slate-400 font-medium uppercase">Facturas NCF</p>
                                <p class="text-sm sm:text-base font-bold text-white mt-1">12 NCF</p>
                                <span class="text-[9px] text-slate-400 font-bold">B01 Emitidos</span>
                            </div>
                        </div>

                        <!-- Gráfico de Ventas Simulado con CSS -->
                        <div class="mt-2">
                            <p class="text-[10px] text-slate-400 font-medium uppercase mb-3">Tendencia Semanal</p>
                            <div class="flex items-end justify-between h-20 gap-2 border-b border-white/[0.06] pb-1 px-2">
                                <div class="bg-indigo-500/20 hover:bg-indigo-500/40 rounded-t w-full h-[30%] transition-all duration-300 relative group">
                                    <div class="absolute -top-6 left-1/2 transform -translate-x-1/2 bg-slate-900 border border-white/10 px-1 py-0.5 rounded text-[8px] opacity-0 group-hover:opacity-100 transition-opacity">RD$3K</div>
                                </div>
                                <div class="bg-indigo-500/20 hover:bg-indigo-500/40 rounded-t w-full h-[55%] transition-all duration-300 relative group">
                                    <div class="absolute -top-6 left-1/2 transform -translate-x-1/2 bg-slate-900 border border-white/10 px-1 py-0.5 rounded text-[8px] opacity-0 group-hover:opacity-100 transition-opacity">RD$6K</div>
                                </div>
                                <div class="bg-indigo-500/20 hover:bg-indigo-500/40 rounded-t w-full h-[40%] transition-all duration-300 relative group">
                                    <div class="absolute -top-6 left-1/2 transform -translate-x-1/2 bg-slate-900 border border-white/10 px-1 py-0.5 rounded text-[8px] opacity-0 group-hover:opacity-100 transition-opacity">RD$4K</div>
                                </div>
                                <div class="bg-indigo-600 hover:bg-indigo-500 rounded-t w-full h-[90%] transition-all duration-300 relative group">
                                    <div class="absolute -top-6 left-1/2 transform -translate-x-1/2 bg-indigo-500 border border-indigo-400 px-1 py-0.5 rounded text-[8px] opacity-0 group-hover:opacity-100 transition-opacity font-bold">RD$18K</div>
                                </div>
                            </div>
                            <div class="flex justify-between text-[9px] text-slate-500 mt-1 px-1">
                                <span>Lun</span>
                                <span>Mar</span>
                                <span>Mié</span>
                                <span>Hoy</span>
                            </div>
                        </div>
                    </div>

                    {{-- Footer del Navegador --}}
                    <div class="border-t border-white/[0.04] pt-3 flex items-center justify-between text-[10px] text-slate-500">
                        <span>BM Business OS v1.2</span>
                        <div class="flex gap-2">
                            <span>DGII Conectada</span>
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 self-center"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ============ PARA QUIÉN ES ============ --}}
    <section class="max-w-5xl mx-auto mb-20 sm:mb-32 border-t border-white/[0.06] pt-16 sm:pt-24">
        <div class="text-center mb-12">
            <p class="text-xs font-bold uppercase tracking-widest text-indigo-400 mb-2">Para quién es</p>
            <h2 class="text-2xl sm:text-4xl font-extrabold text-white">Si llevas las cuentas en un cuaderno, esto es para ti</h2>
            <p class="text-slate-400 text-sm sm:text-base mt-3 max-w-xl mx-auto">Diseñado para adaptarse a las necesidades reales de los comercios dominicanos sin tecnicismos innecesarios.</p>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
            <div class="p-5 rounded-2xl bg-white/[0.02] border border-white/[0.04] hover:-translate-y-1 hover:border-indigo-500/30 hover:bg-white/[0.03] transition-all duration-300">
                <span class="text-2xl mb-3 block">🏪</span>
                <h3 class="text-sm sm:text-base font-bold text-white mb-1">Colmados y Minimarkets</h3>
                <p class="text-xs text-slate-400 leading-relaxed">Arqueo de caja diaria, ventas rápidas y control de fiaos de clientes.</p>
            </div>
            <div class="p-5 rounded-2xl bg-white/[0.02] border border-white/[0.04] hover:-translate-y-1 hover:border-indigo-500/30 hover:bg-white/[0.03] transition-all duration-300">
                <span class="text-2xl mb-3 block">⚙️</span>
                <h3 class="text-sm sm:text-base font-bold text-white mb-1">Repuestos y Ferreterías</h3>
                <p class="text-xs text-slate-400 leading-relaxed">Catálogo con miles de partes, stock bajo y lectura rápida de códigos.</p>
            </div>
            <div class="p-5 rounded-2xl bg-white/[0.02] border border-white/[0.04] hover:-translate-y-1 hover:border-indigo-500/30 hover:bg-white/[0.03] transition-all duration-300">
                <span class="text-2xl mb-3 block">🍔</span>
                <h3 class="text-sm sm:text-base font-bold text-white mb-1">Comida Rápida y Cafés</h3>
                <p class="text-xs text-slate-400 leading-relaxed">Toma de pedidos ágil, envíos automáticos y comanda interna.</p>
            </div>
            <div class="p-5 rounded-2xl bg-white/[0.02] border border-white/[0.04] hover:-translate-y-1 hover:border-indigo-500/30 hover:bg-white/[0.03] transition-all duration-300">
                <span class="text-2xl mb-3 block">💰</span>
                <h3 class="text-sm sm:text-base font-bold text-white mb-1">Prestamistas</h3>
                <p class="text-xs text-slate-400 leading-relaxed">Cálculo automático de cuotas, cobros programados y moras fijas.</p>
            </div>
            <div class="p-5 rounded-2xl bg-white/[0.02] border border-white/[0.04] hover:-translate-y-1 hover:border-indigo-500/30 hover:bg-white/[0.03] transition-all duration-300">
                <span class="text-2xl mb-3 block">👗</span>
                <h3 class="text-sm sm:text-base font-bold text-white mb-1">Tiendas de Ropa</h3>
                <p class="text-xs text-slate-400 leading-relaxed">Gestión de variantes (colores, tallas) y catálogo visual en línea.</p>
            </div>
            <div class="p-5 rounded-2xl bg-white/[0.02] border border-white/[0.04] hover:-translate-y-1 hover:border-indigo-500/30 hover:bg-white/[0.03] transition-all duration-300">
                <span class="text-2xl mb-3 block">🛵</span>
                <h3 class="text-sm sm:text-base font-bold text-white mb-1">Negocios con Delivery</h3>
                <p class="text-xs text-slate-400 leading-relaxed">Asignación automática a repartidores y seguimiento de cuadre en la calle.</p>
            </div>
        </div>
    </section>

    {{-- ============ QUÉ INCLUYE ============ --}}
    <section class="max-w-5xl mx-auto mb-20 sm:mb-32 border-t border-white/[0.06] pt-16 sm:pt-24">
        <div class="text-center mb-12">
            <p class="text-xs font-bold uppercase tracking-widest text-indigo-400 mb-2">Características</p>
            <h2 class="text-2xl sm:text-4xl font-extrabold text-white">Los módulos que de verdad usas</h2>
            <p class="text-slate-400 text-sm sm:text-base mt-3 max-w-xl mx-auto">Herramientas profesionales conectadas para evitar la duplicación de datos e impulsar tu productividad.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach ($destacados as $m)
                <article class="p-6 rounded-2xl bg-white/[0.02] border border-white/[0.05] hover:border-indigo-500/30 hover:bg-white/[0.03] transition-all duration-300 flex flex-col justify-between">
                    <div>
                        <div class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-400 flex items-center justify-center mb-4">
                            @if ($m['clave'] === 'pos')
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 0 0 6 3.75v16.5a2.25 2.25 0 0 0 2.25 2.25h7.5A2.25 2.25 0 0 0 18 20.25V3.75a2.25 2.25 0 0 0-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3"/></svg>
                            @elseif ($m['clave'] === 'inventory')
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M11.25 12h2.25M9 9h6M4.5 5.25h15"/></svg>
                            @elseif ($m['clave'] === 'whatsapp')
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z"/></svg>
                            @elseif ($m['clave'] === 'social')
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a3 3 0 0 0 3-3V12a3 3 0 0 0-3-3h-.008a3 3 0 0 0-3 3v3.72a3 3 0 0 0 3 3ZM18 18.72a3 3 0 0 0 3-3V12a3 3 0 0 0-3-3h-.008a3 3 0 0 0-3 3v3.72a3 3 0 0 0 3 3ZM6 18.72a3 3 0 0 0 3-3V12a3 3 0 0 0-3-3H5.992a3 3 0 0 0-3 3v3.72a3 3 0 0 0 3 3ZM6 18.72a3 3 0 0 0 3-3V12a3 3 0 0 0-3-3H5.992a3 3 0 0 0-3 3v3.72a3 3 0 0 0 3 3ZM12 18.72a3 3 0 0 0 3-3V12a3 3 0 0 0-3-3h-.008a3 3 0 0 0-3 3v3.72a3 3 0 0 0 3 3Z"/></svg>
                            @elseif ($m['clave'] === 'billing')
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
                            @else
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z"/></svg>
                            @endif
                        </div>
                        <h3 class="text-lg font-bold text-white mb-2">{{ $m['nombre'] }}</h3>
                        <p class="text-sm text-slate-400 leading-relaxed">{{ $m['detalle'] }}</p>
                    </div>
                </article>
            @endforeach
        </div>

        @if ($resto !== [])
            <div class="mt-8 text-center text-xs sm:text-sm text-slate-500">
                Y además: <span class="text-slate-400 font-medium">{{ implode(' · ', $resto) }}</span>.
            </div>
        @endif
    </section>

    {{-- ============ CUÁNTO CUESTA ============ --}}
    <section class="max-w-5xl mx-auto mb-20 sm:mb-32 border-t border-white/[0.06] pt-16 sm:pt-24">
        <div class="text-center mb-12">
            <p class="text-xs font-bold uppercase tracking-widest text-indigo-400 mb-2">Cuánto cuesta</p>
            <h2 class="text-2xl sm:text-4xl font-extrabold text-white">Un precio claro, sin sorpresas</h2>
            <p class="text-slate-400 text-sm sm:text-base mt-3 max-w-xl mx-auto">Selecciona el plan que se adapte al tamaño actual de tu negocio y escala cuando lo necesites.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
            @foreach ($planes as $plan)
                @php
                    $esPlanMedio = $loop->iteration === 2; // Suponemos que el segundo plan es el más popular/recomendado
                @endphp
                <article class="relative flex flex-col justify-between p-6 sm:p-8 rounded-3xl transition-all duration-300 {{ $esPlanMedio ? 'bg-gradient-to-b from-indigo-900/50 to-indigo-950/20 border-2 border-indigo-500/80 shadow-indigo-600/10' : 'bg-white/[0.02] border border-white/[0.06] hover:border-white/10' }} shadow-xl">
                    
                    @if ($esPlanMedio)
                        <span class="absolute top-0 right-1/2 translate-x-1/2 -translate-y-1/2 px-3 py-1 bg-indigo-500 text-[10px] font-black text-white tracking-widest uppercase rounded-full shadow-lg">Recomendado</span>
                    @endif

                    <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">{{ $plan->name }}</p>
                        <p class="text-3xl sm:text-4xl font-black text-white mb-4">
                            {{ money($plan->price) }}
                            <span class="text-xs sm:text-sm text-slate-400 font-medium font-sans">/{{ $plan->billing_cycle->value === 'yearly' ? 'año' : 'mes' }}</span>
                        </p>
                        @if (filled($plan->description ?? null))
                            <p class="text-xs sm:text-sm text-slate-400 leading-relaxed mb-6">{{ $plan->description }}</p>
                        @endif
                    </div>

                    <div class="space-y-4 pt-4 border-t border-white/[0.06]">
                        <ul class="text-xs text-slate-300 space-y-2 mb-6">
                            <li class="flex items-center gap-2">
                                <svg class="w-3.5 h-3.5 text-indigo-400 shrink-0" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                                <span>{{ $plan->max_users ? 'Hasta ' . $plan->max_users . ' usuarios' : 'Usuarios ilimitados' }}</span>
                            </li>
                            <li class="flex items-center gap-2">
                                <svg class="w-3.5 h-3.5 text-indigo-400 shrink-0" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                                <span>{{ $plan->max_branches ? 'Hasta ' . $plan->max_branches . ' sucursales' : 'Sucursales ilimitadas' }}</span>
                            </li>
                            <li class="flex items-center gap-2">
                                <svg class="w-3.5 h-3.5 text-indigo-400 shrink-0" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                                <span>Soporte local garantizado</span>
                            </li>
                        </ul>
                        <a href="{{ route('register.form') }}" class="block w-full text-center py-2.5 rounded-xl font-bold text-xs sm:text-sm transition-all duration-200 {{ $esPlanMedio ? 'bg-indigo-600 hover:bg-indigo-500 text-white shadow-md shadow-indigo-600/20' : 'bg-white/10 hover:bg-white/20 text-white' }}">
                            Comenzar Prueba
                        </a>
                    </div>
                </article>
            @endforeach
        </div>

        <p class="text-center mt-8 text-xs sm:text-sm">
            <a href="{{ route('plans.public') }}" class="text-indigo-400 font-semibold hover:text-indigo-300 transition duration-200">Ver qué trae cada plan detalladamente &rarr;</a>
        </p>
    </section>

    {{-- ============ EL CIERRE ============ --}}
    <section class="max-w-5xl mx-auto mb-20 sm:mb-32">
        <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-indigo-900/40 via-indigo-950/20 to-black/80 border border-white/[0.08] p-8 sm:p-12 text-center shadow-2xl">
            <div class="absolute top-0 left-0 w-full h-full bg-[radial-gradient(ellipse_at_center,rgba(99,102,241,0.15),transparent)] pointer-events-none"></div>
            
            <h2 class="text-2xl sm:text-4xl font-extrabold text-white mb-4 relative z-10">Pruébalo con tu propio negocio</h2>
            <p class="text-slate-300 text-sm sm:text-base max-w-xl mx-auto mb-8 relative z-10 leading-relaxed">
                Tienes {{ $diasPrueba }} días libres de cargo para configurar tus productos, realizar tus primeras ventas y ver si cuadra para ti.
            </p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4 max-w-md mx-auto relative z-10">
                <a href="{{ route('register.form') }}" class="w-full sm:w-auto inline-flex items-center justify-center px-8 py-3.5 rounded-2xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-base transition duration-200">
                    Crear mi cuenta gratis
                </a>
                <a href="{{ $enlaceWhatsapp }}" target="_blank" rel="noopener" class="w-full sm:w-auto inline-flex items-center justify-center px-8 py-3.5 rounded-2xl bg-emerald-600/90 hover:bg-emerald-600 text-white font-bold text-base transition duration-200 gap-2">
                    Prefiero que me expliquen
                </a>
            </div>
            <p class="mt-4 text-xs text-slate-500 relative z-10 font-medium">No se requiere tarjeta de crédito para iniciar la prueba.</p>
        </div>
    </section>

    {{-- ============ PIE DE PÁGINA ============ --}}
    <footer class="border-t border-white/[0.06] pt-8 pb-12 flex flex-col sm:flex-row items-center justify-between text-xs sm:text-sm text-slate-500 gap-4">
        <span>© {{ date('Y') }} {{ config('platform.name') }}. Todos los derechos reservados.</span>
        <div class="flex items-center gap-4">
            <a href="{{ route('login') }}" class="text-slate-400 hover:text-white transition duration-200">Ya tengo cuenta (Iniciar Sesión)</a>
        </div>
    </footer>

</div>

{{-- Estilos Inline auxiliares para animaciones y utilidades --}}
<style>
    /* Efecto de difuminado extra para los orbes brillantes */
    .blur-[120px] {
        filter: blur(120px);
    }
    .blur-[130px] {
        filter: blur(130px);
    }
    /* Para evitar parpadeos de Alpine antes de inicializar */
    [x-cloak] {
        display: none !important;
    }
</style>

</body>
</html>
