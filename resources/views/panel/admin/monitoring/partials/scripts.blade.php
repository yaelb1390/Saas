    {{--
        En línea y no en `@push`: el layout del panel NO tiene `@stack('scripts')`, así que un push se
        traga el guion en silencio y la gráfica no se dibujaría nunca. Es como lo hacen Reportes, el
        Dashboard y el reporte de automatizaciones.
    --}}
    <script>
        /* Chart.js BAJO DEMANDA: `window.loadChart()` lo trae en su propio archivo para no lastrar
           las pantallas que no dibujan nada. Nunca un import estático. */
        function sucesosPorDia(etiquetas, normales, problemas) {
            return {
                async init() {
                    const Chart = await window.loadChart();

                    new Chart(this.$refs.lienzo, {
                        type: 'bar',
                        data: {
                            labels: etiquetas.map((d) => d.slice(8) + '/' + d.slice(5, 7)),
                            datasets: [
                                {
                                    label: 'Normales',
                                    data: normales,
                                    backgroundColor: '#c7d2fe',
                                    borderRadius: 3,
                                },
                                {
                                    /* Los problemas ARRIBA de la pila y en ámbar: es lo que se busca
                                       de un vistazo, y abajo quedarían aplastados contra el eje. */
                                    label: 'Problemas',
                                    data: problemas,
                                    backgroundColor: '#f59e0b',
                                    borderRadius: 3,
                                },
                            ],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                x: { stacked: true, grid: { display: false }, ticks: { font: { size: 10 } } },
                                y: {
                                    stacked: true,
                                    beginAtZero: true,
                                    // Sin decimales: son sucesos contados, no una media.
                                    ticks: { precision: 0, font: { size: 10 } },
                                    grid: { color: '#f1f3f9' },
                                },
                            },
                            plugins: { legend: { display: false } },
                        },
                    });
                },
            };
        }
    </script>
