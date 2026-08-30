{{--
    La marca BMIA en pequeño, para avatares de 22–36px.

    Es la misma silueta que [x-panel.logo-bmia] pero con colores planos y sin `<defs>`. Dos razones,
    las dos prácticas: a 24px un degradado no se distingue de un color liso, y esta versión aparece
    una vez POR MENSAJE del hilo, así que la de degradados repetiría los mismos `id` decenas de veces
    en la página —válido a efectos de dibujo, porque gana el primero, pero HTML inválido—.
--}}
<svg {{ $attributes->merge(['class' => 'bmos-logo-mini']) }}
     viewBox="0 0 304 136" role="img" aria-label="BMIA">
    <path fill="#3d8bfd" d="M90,6 L120,6 L190,93 L260,6 L290,6 L290,126 L260,126 L260,43 L190,130 Z"/>
    <path fill="#ffffff" fill-rule="evenodd"
          d="M14,6 H100 A24,24 0 0 1 124,30 V40 A24,24 0 0 1 100,64
             H108 A26,26 0 0 1 134,90 V100 A26,26 0 0 1 108,126 H14 Z
             M42,24 H95 A11,11 0 0 1 95,46 H42 Z
             M42,84 H104 A12,12 0 0 1 104,108 H42 Z"/>
</svg>
