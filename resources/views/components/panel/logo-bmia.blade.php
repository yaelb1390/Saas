{{-- Símbolo BMIA de la barra lateral.

     Va dibujado en SVG y no como PNG por dos motivos concretos: se ve nítido en cualquier
     densidad de pantalla (la barra lo muestra a 156px, un mapa de bits ahí llega borroso en
     pantallas retina) y su fondo es transparente, así que se apoya en el degradado del panel
     en lugar de recortar un rectángulo oscuro que nunca casaría con él.

     Solo va el símbolo, sin la palabra «BMIA» debajo: en una barra de 264px esa línea comía
     alto de cabecera y empujaba el menú hacia abajo. El nombre no se pierde para quien usa
     lector de pantalla porque viaja en el aria-label.

     El halo tampoco está horneado en la imagen: lo pone el CSS con drop-shadow, para poder
     ajustarlo sin volver a exportar nada. --}}
<svg {{ $attributes->merge(['class' => 'bmos-logo-mark']) }}
     viewBox="0 0 304 136" role="img" aria-label="BMIA">
    <defs>
        {{-- Degradado de la M: arranca casi blanco donde nace de la B y va cerrando hacia
             el azul intenso del ala derecha. --}}
        <linearGradient id="bmia-m" gradientUnits="userSpaceOnUse" x1="90" y1="10" x2="290" y2="120">
            <stop offset="0" stop-color="#f2f7ff"/>
            <stop offset="0.16" stop-color="#9fd6fe"/>
            <stop offset="0.38" stop-color="#3ba6f8"/>
            <stop offset="0.62" stop-color="#0f6cf2"/>
            <stop offset="1" stop-color="#1c46e2"/>
        </linearGradient>
        <linearGradient id="bmia-b" gradientUnits="userSpaceOnUse" x1="14" y1="6" x2="134" y2="126">
            <stop offset="0" stop-color="#ffffff"/>
            <stop offset="1" stop-color="#dfe8ff"/>
        </linearGradient>
    </defs>

    {{-- La M se pinta primero a propósito: nace por detrás de la B y donde ambas se solapan
         manda el blanco, así el contorno de la B se lee limpio como en el logotipo original.
         El trazo baja, sube hasta el pico y cae recto; el hueco entre la subida y el asta
         derecha es lo que hace que se lea como M y no como una cuña maciza. --}}
    <path fill="url(#bmia-m)"
          d="M90,6 L120,6 L190,93 L260,6 L290,6 L290,126 L260,126 L260,43 L190,130 Z"/>

    {{-- B de dos panzas: contorno exterior más los dos ojos. Con evenodd los ojos calan en
         vez de taparse, y así se ve a través el fondo de la barra. --}}
    <path fill="url(#bmia-b)" fill-rule="evenodd"
          d="M14,6 H100 A24,24 0 0 1 124,30 V40 A24,24 0 0 1 100,64
             H108 A26,26 0 0 1 134,90 V100 A26,26 0 0 1 108,126 H14 Z
             M42,24 H95 A11,11 0 0 1 95,46 H42 Z
             M42,84 H104 A12,12 0 0 1 104,108 H42 Z"/>
</svg>
