{{--
    El estado de la conexión en las pantallas de cobro.

    Un cajero no mira el icono del wifi: mira la pantalla en la que está cobrando. Si la línea se cae
    y nada se lo dice, sigue vendiendo sin saber que sus ventas están esperando en el equipo, y el
    día que ese equipo se apague o se cambie, esas ventas no existen para nadie.

    Por eso esto no es un adorno de estado: es la única señal de que hay dinero cobrado que todavía
    no está en el sistema. Mientras quede algo pendiente, se ve. En verde cuando ya está todo subido
    no se enseña nada, porque «todo bien» no necesita ocupar sitio en un mostrador.

    Vive dentro del componente Alpine de cada terminal y lee su objeto `sinLinea`.
--}}
<div x-show="sinLinea.conexion === 'sin-conexion' || sinLinea.pendientes > 0 || sinLinea.apartadas > 0"
     x-cloak
     class="bmos-conexion"
     :data-estado="sinLinea.apartadas > 0 ? 'grave'
        : (sinLinea.conexion === 'sin-conexion' ? 'sin-linea' : 'subiendo')">

    <span class="bmos-conexion-punto" aria-hidden="true"></span>

    <div class="bmos-conexion-texto">
        <p x-show="sinLinea.conexion === 'sin-conexion'">
            Sin conexión. Puedes seguir cobrando: las ventas se guardan aquí.
            <span x-show="catalogoAntiguedad" x-text="'(' + catalogoAntiguedad + ')'" class="bmos-conexion-nota"></span>
        </p>

        <p x-show="sinLinea.conexion !== 'sin-conexion' && sinLinea.pendientes > 0">
            <span x-show="sinLinea.subiendo">Enviando</span>
            <span x-show="!sinLinea.subiendo">Por enviar</span>
            <span x-text="sinLinea.pendientes"></span>
            <span x-text="sinLinea.pendientes === 1 ? 'venta' : 'ventas'"></span>
            cobradas sin conexión.
        </p>

        {{-- La sesión caducó mientras el terminal estaba a oscuras. La cola NO se toca; hay que
             volver a entrar para poder subirla. Se dice así de claro porque el cajero necesita
             saber que el dinero sigue guardado. --}}
        <p x-show="sinLinea.pideLogin" class="bmos-conexion-nota">
            Vuelve a iniciar sesión para enviarlas. No se ha perdido ninguna.
        </p>

        {{-- Lo único que de verdad exige que alguien actúe. --}}
        <p x-show="sinLinea.apartadas > 0">
            <strong>
                <span x-text="sinLinea.apartadas"></span>
                <span x-text="sinLinea.apartadas === 1 ? 'venta cobrada no pudo registrarse' : 'ventas cobradas no pudieron registrarse'"></span>.
            </strong>
            Avisa al encargado: el dinero se cobró y no está en el sistema.
        </p>
    </div>
</div>
