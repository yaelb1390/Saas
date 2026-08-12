{{-- Aviso flotante de resultado. Reúne los mensajes de éxito (panel_ok) y de error (panel_error) de
     todo el sistema, más un aviso si hubo errores de validación: así toda acción que guarda algo da
     un acuse claro y ningún fallo pasa desapercibido.

     Lo pinta SweetAlert2 (ver `avisoFlash` en app.js) en modo toast, para que haya UN solo sistema
     de avisos en la aplicación en vez de dos que se parecen pero no son iguales. Sigue sin bloquear:
     esto sale tras cada guardado del panel y un diálogo que hay que cerrar convertiría cada acción
     rutinaria en dos clics.

     La librería se carga bajo demanda y este bloque solo se emite cuando HAY mensaje, así que las
     pantallas sin aviso no descargan nada. --}}
@php
    // El POS tiene su propio aviso (con botón de recibo), así que aquí solo van los guardados de
    // formularios del panel, que es donde faltaba el acuse.
    $okMsg = session('panel_ok');
    // Mensaje de error concreto: primero el del servicio, y si fue validación, el PRIMER error real
    // (p. ej. «El código de barras ya ha sido tomado») en vez de un texto genérico. Así el usuario
    // sabe exactamente qué corregir.
    $errMsg = session('panel_error') ?? ($errors->any() ? $errors->first() : null);
@endphp

@if ($okMsg || $errMsg)
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            {{-- Con `?.`: si el JavaScript no cargó, el aviso se pierde pero la página no se rompe. --}}
            window.avisoFlash?.(@js($okMsg
                ? ['tipo' => 'success', 'titulo' => 'Registro exitoso', 'texto' => $okMsg]
                : ['tipo' => 'error', 'titulo' => 'No se pudo guardar',
                   'texto' => $errMsg ?? 'Revisa los datos marcados en el formulario e inténtalo de nuevo.']));
        });
    </script>
@endif
