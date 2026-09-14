{{--
    Bluetooth: el selector nativo del navegador, y lo que hace falta al rededor de eso.

    NO HAY UNA LISTA DE «CERCANAS» ANTES DE PULSAR EL BOTÓN. El navegador no deja enumerar
    dispositivos por su cuenta; el único gesto posible es abrir SU selector y que la persona elija
    ahí. Lo que esta pestaña añade es todo lo de alrededor: el aviso claro cuando no se puede, el
    indicador de búsqueda, y qué hacer con lo que se eligió.
--}}
<div class="bmos-card bmos-card-pad max-w-2xl">
    <template x-if="!bt.soportado">
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p class="font-semibold">⚠️ Bluetooth no está disponible aquí</p>
            <p class="mt-1" x-text="bt.motivo"></p>
            <p class="mt-2 text-xs">
                Se puede seguir registrando la impresora a mano en «Buscar impresoras» — solo el
                emparejado automático necesita esto.
            </p>
        </div>
    </template>

    <template x-if="bt.soportado">
        <div>
            <p class="text-sm text-slate-600">
                Al pulsar, el propio navegador abre su ventana para elegir el dispositivo — así lo exige
                Bluetooth por seguridad, y ninguna página puede saltárselo ni listar nada antes de eso.
            </p>

            <button type="button" @click="buscarBluetooth()" :disabled="bt.buscando"
                    class="bmos-btn bmos-btn-primary mt-3">
                <span x-show="!bt.buscando">🔍 Buscar dispositivos</span>
                <span x-show="bt.buscando" x-cloak>Abriendo el selector…</span>
            </button>

            <p class="mt-2 text-xs text-slate-400">
                Si el sistema operativo pide permiso para usar Bluetooth, acéptalo — sin eso el
                navegador no puede ni mostrar el selector.
            </p>

            <div class="mt-4 divide-y divide-slate-100 border-t border-slate-100">
                <template x-if="bt.dispositivos.length === 0">
                    <p class="bmos-empty">Nada emparejado todavía en esta sesión.</p>
                </template>

                <template x-for="item in bt.dispositivos" :key="item.deviceId">
                    <div class="flex flex-wrap items-center justify-between gap-2 py-3">
                        <div>
                            <p class="font-medium text-slate-800" x-text="item.name"></p>
                            <p class="font-mono text-xs text-slate-400" x-text="item.deviceId"></p>
                            <p class="mt-0.5 text-xs" :class="item.conectado ? 'text-emerald-600' : 'text-slate-400'">
                                <span x-show="item.conectado">✅ Conectada<template x-if="item.bateria !== null"><span x-text="' · 🔋 ' + item.bateria + '%'"></span></template></span>
                                <span x-show="!item.conectado">Sin conectar</span>
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-1.5 text-xs">
                            <button type="button" @click="conectarBluetooth(item)" :disabled="item.conectando"
                                    class="bmos-btn bmos-btn-ghost">
                                <span x-show="!item.conectando" x-text="item.conectado ? 'Reconectar' : 'Emparejar y conectar'"></span>
                                <span x-show="item.conectando" x-cloak>Conectando…</span>
                            </button>
                            @can('printing.manage')
                                <button type="button" @click="registrarDesdeBluetooth(item)" class="bmos-btn bmos-btn-ghost">Registrarla</button>
                            @endcan
                            <button type="button" @click="olvidarBluetooth(item)" class="bmos-btn bmos-btn-ghost text-rose-600">Olvidar</button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </template>
</div>
