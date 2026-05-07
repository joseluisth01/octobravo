document.addEventListener('DOMContentLoaded', function() {
    // Inicializar cada bloque de semáforo independientemente
    const bloquesSemaforo = document.querySelectorAll('.textosemaforo');
    
    bloquesSemaforo.forEach(function(bloque) {
        animateTextosemaforo(bloque);
    });
    
    function animateTextosemaforo(contenedor) {
        const items = contenedor.querySelectorAll('.semaforo-item');
        const imagenes = contenedor.querySelectorAll('.semaforo-imagen-gif');
        
        // Usar las URLs pasadas desde PHP
        const imagenInactiva = textoSemaforoData.imagenInactiva;
        const imagenActiva = textoSemaforoData.imagenActiva;
        
        let index = 0;

        function resetItems() {
            items.forEach((item, i) => {
                item.classList.remove('active');
                if (imagenes[i]) {
                    imagenes[i].src = imagenInactiva;
                }
            });
        }

        function activateNext() {
            if (index < items.length) {
                // Activar el item actual
                items[index].classList.add('active');
                if (imagenes[index]) {
                    imagenes[index].src = imagenActiva;
                }
                index++;
                setTimeout(activateNext, 2000);
            } else {
                // Todos están activos, esperar 2 segundos y resetear
                setTimeout(() => {
                    resetItems();
                    index = 0;
                    // Reiniciar inmediatamente
                    activateNext();
                }, 2000);
            }
        }

        if (items.length > 0) {
            activateNext();
        }
    }
});