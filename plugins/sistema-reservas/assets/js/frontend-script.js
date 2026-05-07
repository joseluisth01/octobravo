// Variables globales
let currentStep = 1;
let currentDate = new Date();
let selectedDate = null;
let selectedServiceId = null;
let servicesData = {};
let diasAnticiapcionMinima = 1; // ✅ NUEVA VARIABLE GLOBAL

jQuery(document).ready(function ($) {

    // Inicializar formulario de reservas
    initBookingForm();

    function initBookingForm() {
        // Cargar configuración primero, luego calendario
        loadSystemConfiguration().then(() => {
            loadCalendar();
            setupEventListeners();

            initializePricing();
        });
    }

    function initializePricing() {
        $('#total-price').text('0€');
        $('#total-discount').text('');
        $('#discount-row').hide();
        $('#discount-message').removeClass('show');
        console.log('Precios inicializados con 0€');
    }

    function loadSystemConfiguration() {
        return new Promise((resolve, reject) => {
            const formData = new FormData();
            formData.append('action', 'get_configuration');
            formData.append('nonce', reservasAjax.nonce);

            fetch(reservasAjax.ajax_url, {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const config = data.data;
                        diasAnticiapcionMinima = parseInt(config.servicios?.dias_anticipacion_minima?.value || '1');
                        console.log('Días de anticipación mínima cargados:', diasAnticiapcionMinima);
                        resolve();
                    } else {
                        console.warn('No se pudo cargar configuración, usando valores por defecto');
                        diasAnticiapcionMinima = 1;
                        resolve();
                    }
                })
                .catch(error => {
                    console.error('Error cargando configuración:', error);
                    diasAnticiapcionMinima = 1;
                    resolve();
                });
        });
    }

    function setupEventListeners() {
        // Navegación del calendario
        $('#prev-month').on('click', function () {
            currentDate.setMonth(currentDate.getMonth() - 1);
            loadCalendar();
        });

        $('#next-month').on('click', function () {
            currentDate.setMonth(currentDate.getMonth() + 1);
            loadCalendar();
        });

        // Selección de horario
        $('#horarios-select').on('change', function () {
            selectedServiceId = $(this).val();
            if (selectedServiceId) {
                $('#btn-siguiente').prop('disabled', false);
                loadPrices();
            } else {
                $('#btn-siguiente').prop('disabled', true);
                // ✅ Si no hay servicio seleccionado, mostrar 0€
                $('#total-price').text('0€');
            }
        });

        // ✅ CAMBIOS EN SELECTORES DE PERSONAS - MEJORADO
        $('#adultos, #residentes, #ninos-5-12, #ninos-menores').on('input change keyup', function () {
            // Delay pequeño para mejor UX
            setTimeout(() => {
                calculateTotalPrice();
                validatePersonSelection();
            }, 100);
        });

        // Navegación entre pasos
        $('#btn-siguiente').on('click', function () {
            nextStep();
        });

        $('#btn-anterior').on('click', function () {
            previousStep();
        });
    }

    function loadCalendar() {
        updateCalendarHeader();

        const formData = new FormData();
        formData.append('action', 'get_available_services');
        formData.append('month', currentDate.getMonth() + 1);
        formData.append('year', currentDate.getFullYear());
        formData.append('nonce', reservasAjax.nonce);

        fetch(reservasAjax.ajax_url, {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    servicesData = data.data;
                    renderCalendar();
                } else {
                    console.error('Error cargando servicios:', data.data);
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
    }

    function updateCalendarHeader() {
        const monthNames = [
            'ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO',
            'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'
        ];

        const monthYear = monthNames[currentDate.getMonth()] + ' ' + currentDate.getFullYear();
        $('#current-month-year').text(monthYear);
    }

    function renderCalendar() {
        const year = currentDate.getFullYear();
        const month = currentDate.getMonth();

        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);
        let firstDayOfWeek = firstDay.getDay();
        firstDayOfWeek = (firstDayOfWeek + 6) % 7; // Lunes = 0

        const daysInMonth = lastDay.getDate();
        const dayNames = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];

        let calendarHTML = '';

        // Encabezados de días
        dayNames.forEach(day => {
            calendarHTML += `<div class="calendar-day-header">${day}</div>`;
        });

        // Días del mes anterior
        for (let i = 0; i < firstDayOfWeek; i++) {
            const dayNum = new Date(year, month, -firstDayOfWeek + i + 1).getDate();
            calendarHTML += `<div class="calendar-day other-month">${dayNum}</div>`;
        }

        // ✅ FECHA ACTUAL CORREGIDA
        const today = new Date();
        const todayDateStr = today.toISOString().split('T')[0]; // Formato YYYY-MM-DD

        console.log(`Configuración frontend: ${diasAnticiapcionMinima} días de anticipación`);
        console.log(`Fecha actual: ${today.toDateString()}`);
        console.log(`Fecha actual string: ${todayDateStr}`);

        // Días del mes actual
        for (let day = 1; day <= daysInMonth; day++) {
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const dayDate = new Date(year, month, day);
            dayDate.setHours(0, 0, 0, 0); // Normalizar horas

            let dayClass = 'calendar-day';
            let clickHandler = '';

            // ✅ LÓGICA CORREGIDA
            const isToday = dateStr === todayDateStr;
            const isPastDate = dayDate < new Date(today.getFullYear(), today.getMonth(), today.getDate());

            // ✅ APLICAR RESTRICCIONES
            let isBlocked = false;

            if (isPastDate && !isToday) {
                // Fechas pasadas (pero no hoy) siempre bloqueadas
                isBlocked = true;
                console.log(`Día ${day} bloqueado (fecha pasada)`);
            } else if (!isToday && !isPastDate && diasAnticiapcionMinima > 0) {
                // Para fechas futuras (no hoy), aplicar días de anticipación
                const todayNormalized = new Date(today.getFullYear(), today.getMonth(), today.getDate());
                const fechaMinimaFutura = new Date(todayNormalized);
                fechaMinimaFutura.setDate(todayNormalized.getDate() + diasAnticiapcionMinima);

                if (dayDate < fechaMinimaFutura) {
                    isBlocked = true;
                    console.log(`Día ${day} bloqueado por anticipación mínima`);
                }
            }
            // ✅ HOY NUNCA SE BLOQUEA

            console.log(`Día ${day}: es hoy: ${isToday}, es pasado: ${isPastDate}, bloqueado: ${isBlocked}`);

            if (isBlocked) {
                dayClass += ' no-disponible';
            } else if (servicesData[dateStr] && servicesData[dateStr].length > 0) {
                // ✅ HAY SERVICIOS PARA ESTA FECHA
                const servicesAvailable = servicesData[dateStr];
                let hasAvailableServices = false;

                if (isToday) {
                    // ✅ PARA HOY: Verificar que haya servicios con hora posterior a la actual
                    const now = new Date();
                    const currentHour = now.getHours();
                    const currentMinute = now.getMinutes();
                    const currentTimeInMinutes = currentHour * 60 + currentMinute;

                    hasAvailableServices = servicesAvailable.some(service => {
                        const serviceTime = service.hora.split(':');
                        const serviceHour = parseInt(serviceTime[0]);
                        const serviceMinute = parseInt(serviceTime[1]);
                        const serviceTimeInMinutes = serviceHour * 60 + serviceMinute;

                        const isServiceFuture = serviceTimeInMinutes > currentTimeInMinutes;

                        console.log(`Servicio ${service.hora}: ${isServiceFuture ? 'disponible' : 'pasado'} (hora actual: ${currentHour}:${String(currentMinute).padStart(2, '0')})`);

                        return isServiceFuture;
                    });

                    console.log(`Día ${day} (hoy) - Servicios disponibles después de las ${currentHour}:${String(currentMinute).padStart(2, '0')}:`, hasAvailableServices);
                } else {
                    // ✅ PARA DÍAS FUTUROS: Todos los servicios están disponibles
                    hasAvailableServices = servicesAvailable.length > 0;
                    console.log(`Día ${day} (futuro) - Servicios disponibles:`, hasAvailableServices);
                }

                if (hasAvailableServices) {
                    dayClass += ' disponible';
                    clickHandler = `onclick="selectDate('${dateStr}')"`;

                    // Verificar si algún servicio tiene descuento
                    const tieneDescuento = servicesAvailable.some(service =>
                        service.tiene_descuento && parseFloat(service.porcentaje_descuento) > 0
                    );

                    if (tieneDescuento) {
                        dayClass += ' oferta';
                    }
                } else {
                    dayClass += ' no-disponible';
                    console.log(`Día ${day} no disponible (sin servicios válidos para la hora actual)`);
                }
            } else {
                dayClass += ' no-disponible';
                console.log(`Día ${day} no disponible (sin servicios en la fecha)`);
            }

            if (selectedDate === dateStr) {
                dayClass += ' selected';
            }

            calendarHTML += `<div class="${dayClass}" ${clickHandler}>${day}</div>`;
        }

        $('#calendar-grid').html(calendarHTML);

        // Reasignar eventos de clic después de regenerar el HTML
        setupCalendarClickEvents();
    }

    function setupCalendarClickEvents() {
        $('.calendar-day.disponible').off('click').on('click', function () {
            const dayNumber = $(this).text();
            const year = currentDate.getFullYear();
            const month = currentDate.getMonth();
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(dayNumber).padStart(2, '0')}`;

            selectDate(dateStr, $(this));
        });
    }

    function selectDate(dateStr, dayElement) {
        selectedDate = dateStr;
        selectedServiceId = null;

        // Actualizar visual del calendario
        $('.calendar-day').removeClass('selected');
        if (dayElement) {
            dayElement.addClass('selected');
        }

        // Cargar horarios disponibles
        loadAvailableSchedules(dateStr);
    }

    function loadAvailableSchedules(dateStr) {
        const services = servicesData[dateStr] || [];
        const today = new Date();
        const selectedDay = new Date(dateStr + 'T00:00:00');
        const isToday = dateStr === today.toISOString().split('T')[0];

        let optionsHTML = '<option value="">Selecciona un horario</option>';

        services.forEach(service => {
            let shouldShowService = true;

            // ✅ FILTRAR HORAS PASADAS SOLO PARA EL DÍA DE HOY
            if (isToday) {
                const now = new Date();
                const currentHour = now.getHours();
                const currentMinute = now.getMinutes();
                const currentTimeInMinutes = currentHour * 60 + currentMinute;

                const serviceTime = service.hora.split(':');
                const serviceHour = parseInt(serviceTime[0]);
                const serviceMinute = parseInt(serviceTime[1]);
                const serviceTimeInMinutes = serviceHour * 60 + serviceMinute;

                // Solo mostrar servicios futuros para hoy
                shouldShowService = serviceTimeInMinutes > currentTimeInMinutes;

                if (!shouldShowService) {
                    console.log(`Servicio ${service.hora} omitido (hora pasada para hoy)`);
                    return; // Saltar este servicio
                }
            }
            // Para días futuros, mostrar todos los servicios

            let descuentoInfo = '';

            // ✅ LÓGICA MEJORADA PARA MOSTRAR INFORMACIÓN DEL DESCUENTO
            if (service.tiene_descuento && parseFloat(service.porcentaje_descuento) > 0) {
                const porcentaje = parseFloat(service.porcentaje_descuento);
                const tipo = service.descuento_tipo || 'fijo';
                const minimo = parseInt(service.descuento_minimo_personas) || 1;

                if (tipo === 'fijo') {
                    // Descuento fijo para todos
                    descuentoInfo = ` (${porcentaje}% descuento)`;
                } else if (tipo === 'por_grupo') {
                    // Descuento por grupo con mínimo de personas
                    descuentoInfo = ` (${porcentaje}% descuento desde ${minimo} personas)`;
                }
            }

            optionsHTML += `<option value="${service.id}" 
                       data-plazas="${service.plazas_disponibles}"
                       data-descuento-tipo="${service.descuento_tipo || 'fijo'}"
                       data-descuento-minimo="${service.descuento_minimo_personas || 1}">
                    ${service.hora} - ${service.plazas_disponibles} plazas disponibles${descuentoInfo}
                </option>`;
        });

        $('#horarios-select').html(optionsHTML).prop('disabled', false);
        $('#btn-siguiente').prop('disabled', true);
    }

    function loadPrices() {
        if (!selectedServiceId) return;

        const service = findServiceById(selectedServiceId);
        if (service) {
            $('#price-adultos').text(service.precio_adulto + '€');
            $('#price-ninos').text(service.precio_nino + '€');
            calculateTotalPrice();
        }
    }

    function findServiceById(serviceId) {
        for (let date in servicesData) {
            for (let service of servicesData[date]) {
                if (service.id == serviceId) {
                    return service;
                }
            }
        }
        return null;
    }

    function calculateTotalPrice() {
        if (!selectedServiceId) {
            clearPricing();
            return;
        }

        const adultos = parseInt($('#adultos').val()) || 0;
        const residentes = parseInt($('#residentes').val()) || 0;
        const ninos512 = parseInt($('#ninos-5-12').val()) || 0;
        const ninosMenores = parseInt($('#ninos-menores').val()) || 0;

        const totalPersonas = adultos + residentes + ninos512 + ninosMenores;

        // ✅ CAMBIO: Si no hay personas, mostrar 0€ en lugar de limpiar
        if (totalPersonas === 0) {
            $('#total-discount').text('');
            $('#total-price').text('0€'); // ✅ Mostrar 0€ siempre
            $('#discount-row').hide();
            $('#discount-message').removeClass('show');
            console.log('No hay personas seleccionadas - mostrando 0€');
            return;
        }

        // Resto de la función igual...
        const formData = new FormData();
        formData.append('action', 'calculate_price');
        formData.append('service_id', selectedServiceId);
        formData.append('adultos', adultos);
        formData.append('residentes', residentes);
        formData.append('ninos_5_12', ninos512);
        formData.append('ninos_menores', ninosMenores);
        formData.append('nonce', reservasAjax.nonce);

        fetch(reservasAjax.ajax_url, {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const result = data.data;
                    updatePricingDisplay(result);
                } else {
                    console.error('Error calculando precio:', data);
                    // ✅ CAMBIO: En caso de error, mostrar 0€
                    $('#total-price').text('0€');
                    $('#total-discount').text('');
                    $('#discount-row').hide();
                    $('#discount-message').removeClass('show');
                }
            })
            .catch(error => {
                console.error('Error calculando precio:', error);
                // ✅ CAMBIO: En caso de error, mostrar 0€
                $('#total-price').text('0€');
                $('#total-discount').text('');
                $('#discount-row').hide();
                $('#discount-message').removeClass('show');
            });
    }

    function clearPricing() {
        $('#total-discount').text('');
        $('#total-price').text('0€'); // ✅ CAMBIO: Siempre mostrar 0€
        $('#discount-row').hide();
        $('#discount-message').removeClass('show');
        console.log('Precios limpiados - mostrando 0€');
    }

    function updatePricingDisplay(result) {
        console.log('Datos recibidos del servidor:', result);

        // Calcular descuento total para mostrar
        const descuentoTotal = (result.descuento_grupo || 0) + (result.descuento_servicio || 0);

        // Manejar descuentos totales
        if (descuentoTotal > 0) {
            $('#total-discount').text('-' + descuentoTotal.toFixed(2) + '€');
            $('#discount-row').show();
        } else {
            $('#discount-row').hide();
        }

        // ✅ LÓGICA MEJORADA PARA MENSAJES DE DESCUENTO
        let mensajeDescuento = '';

        // Si hay descuento por grupo (reglas globales)
        if (result.regla_descuento_aplicada && result.regla_descuento_aplicada.rule_name && result.descuento_grupo > 0) {
            const regla = result.regla_descuento_aplicada;
            mensajeDescuento = `Descuento del ${regla.discount_percentage}% por ${regla.rule_name.toLowerCase()}`;
        }

        // Si hay descuento específico del servicio aplicado
        if (result.servicio_con_descuento && result.servicio_con_descuento.descuento_aplicado && result.descuento_servicio > 0) {
            const servicio = result.servicio_con_descuento;
            let mensajeServicio = '';

            if (servicio.descuento_tipo === 'fijo') {
                mensajeServicio = `Descuento del ${servicio.porcentaje_descuento}% aplicado a este servicio`;
            } else if (servicio.descuento_tipo === 'por_grupo') {
                mensajeServicio = `Descuento del ${servicio.porcentaje_descuento}% por alcanzar ${servicio.descuento_minimo_personas} personas`;
            }

            // ✅ COMBINAR MENSAJES SI HAY AMBOS DESCUENTOS
            if (mensajeDescuento && mensajeServicio) {
                if (servicio.descuento_acumulable == '1') {
                    mensajeDescuento += ` + ${mensajeServicio}`;
                } else {
                    // Mostrar solo el que tiene prioridad
                    const prioridad = servicio.descuento_prioridad || 'servicio';
                    if (prioridad === 'servicio') {
                        mensajeDescuento = mensajeServicio;
                    }
                    // Si prioridad es 'grupo', ya tenemos el mensaje del grupo
                }
            } else if (mensajeServicio) {
                mensajeDescuento = mensajeServicio;
            }
        }

        // Mostrar mensaje final
        if (mensajeDescuento) {
            $('#discount-text').text(mensajeDescuento);
            $('#discount-message').addClass('show');
            console.log('Mensaje de descuento mostrado:', mensajeDescuento);
        } else {
            $('#discount-message').removeClass('show');
        }

        window.lastDiscountRule = result.regla_descuento_aplicada;

        // Actualizar precio total
        const totalPrice = parseFloat(result.total) || 0;
        $('#total-price').text(totalPrice.toFixed(2) + '€');

        console.log('Precios actualizados:', {
            descuento_grupo: result.descuento_grupo,
            descuento_servicio: result.descuento_servicio,
            descuento_total: descuentoTotal,
            total: totalPrice,
            debug: result.debug
        });
    }

    function validatePersonSelection() {
        const adultos = parseInt($('#adultos').val()) || 0;
        const residentes = parseInt($('#residentes').val()) || 0;
        const ninos512 = parseInt($('#ninos-5-12').val()) || 0;
        const ninosMenores = parseInt($('#ninos-menores').val()) || 0;

        const totalAdults = adultos + residentes;
        const totalChildren = ninos512 + ninosMenores;

        if (totalChildren > 0 && totalAdults === 0) {
            alert('Debe haber al menos un adulto si hay niños en la reserva.');
            $('#ninos-5-12, #ninos-menores').val(0);
            calculateTotalPrice();
            return false;
        }

        return true;
    }

    function nextStep() {
        if (!selectedDate || !selectedServiceId) {
            alert('Por favor, selecciona una fecha y horario.');
            return;
        }

        const adultos = parseInt($('#adultos').val()) || 0;
        const residentes = parseInt($('#residentes').val()) || 0;
        const ninos512 = parseInt($('#ninos-5-12').val()) || 0;
        const ninosMenores = parseInt($('#ninos-menores').val()) || 0;

        const totalPersonas = adultos + residentes + ninos512 + ninosMenores;

        if (totalPersonas === 0) {
            alert('Debe seleccionar al menos una persona.');
            return;
        }

        if (!validatePersonSelection()) {
            return;
        }

        $('#step-2').show();
        $('#btn-siguiente').hide();
    }

    function previousStep() {
        if (currentStep === 2) {
            currentStep = 1;
            $('#step-2').hide();
            $('#step-1').show();
            $('#btn-anterior').hide();
            $('#btn-siguiente').text('Siguiente →').show();

        } else if (currentStep === 3) {
            currentStep = 2;
            $('#step-3').hide();
            $('#step-2').show();
            $('#btn-siguiente').text('Siguiente →').show();
        }
    }

    function resetForm() {
        currentStep = 1;
        selectedDate = null;
        selectedServiceId = null;

        $('#step-2, #step-3').hide();
        $('#step-1').show();
        $('#btn-anterior').hide();
        $('#btn-siguiente').text('Siguiente →').show().prop('disabled', true);

        $('#adultos, #residentes, #ninos-5-12, #ninos-menores').val(0).trigger('change');
        $('#horarios-select').html('<option value="">Selecciona primero una fecha</option>').prop('disabled', true);

        $('.calendar-day').removeClass('selected');

        // ✅ CAMBIO: Usar la función clearPricing que ahora muestra 0€
        clearPricing();
    }

    window.proceedToPayment = function () {
        const service = findServiceById(selectedServiceId);
        const adultos = parseInt($('#adultos').val()) || 0;
        const residentes = parseInt($('#residentes').val()) || 0;
        const ninos512 = parseInt($('#ninos-5-12').val()) || 0;
        const ninosMenores = parseInt($('#ninos-menores').val()) || 0;

        const resumen = `
            RESUMEN DE LA RESERVA:
            
            Fecha: ${selectedDate}
            Hora: ${service.hora}
            
            Adultos: ${adultos}
            Residentes: ${residentes}
            Niños (5-12 años): ${ninos512}
            Niños (-5 años): ${ninosMenores}
            
            Total: ${$('#total-price').text()}
            
            ¿Proceder con la reserva?
        `;

        if (confirm(resumen)) {
            // 🔃 Enviar a Redsys en lugar de mostrar alert
            jQuery.post(reservasAjax.ajax_url, {
                action: "generar_formulario_pago_redsys",
                nonce: reservasAjax.nonce,
                reservation_data: JSON.stringify(reservationData)
            }, function (response) {
                if (response.success) {
                    // Añade el formulario generado y ejecútalo
                    jQuery('body').append(response.data);
                } else {
                    alert("Error al generar el formulario de pago");
                    console.error(response);
                }
            });

            resetForm();
        }
    };

    window.proceedToDetails = function () {
        console.log('=== INICIANDO proceedToDetails CON VERIFICACIÓN ===');

        if (!selectedDate || !selectedServiceId) {
            alert('Error: No hay fecha o servicio seleccionado');
            return;
        }

        const service = findServiceById(selectedServiceId);
        if (!service) {
            alert('Error: No se encontraron datos del servicio');
            return;
        }

        const adultos = parseInt($('#adultos').val()) || 0;
        const residentes = parseInt($('#residentes').val()) || 0;
        const ninos_5_12 = parseInt($('#ninos-5-12').val()) || 0;
        const ninos_menores = parseInt($('#ninos-menores').val()) || 0;

        const total_personas_con_plaza = adultos + residentes + ninos_5_12;

        // ✅ VERIFICACIÓN CRÍTICA DE DISPONIBILIDAD
        console.log('Verificando disponibilidad antes de continuar...');
        console.log('Plazas necesarias:', total_personas_con_plaza);
        console.log('Plazas disponibles según servicio:', service.plazas_disponibles);

        if (total_personas_con_plaza > service.plazas_disponibles) {
            alert(`Lo sentimos, solo quedan ${service.plazas_disponibles} plaza(s) disponible(s). Has intentado reservar ${total_personas_con_plaza} plaza(s). Por favor, reduce el número de personas.`);
            return; // ✅ BLOQUEAR AVANCE
        }

        // ✅ VERIFICACIÓN EN SERVIDOR ANTES DE CONTINUAR
        jQuery.ajax({
            url: reservasAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'verify_availability_before_payment',
                nonce: reservasAjax.nonce,
                service_id: selectedServiceId,
                personas_necesarias: total_personas_con_plaza
            },
            success: function (response) {
                if (response.success) {
                    // ✅ HAY DISPONIBILIDAD, CONTINUAR
                    proceedToDetailsAfterVerification();
                } else {
                    // ❌ NO HAY DISPONIBILIDAD
                    alert(response.data || 'No hay suficientes plazas disponibles');

                    // ✅ RECARGAR DISPONIBILIDAD DEL SERVICIO
                    loadAvailableSchedules(selectedDate);
                }
            },
            error: function () {
                alert('Error verificando disponibilidad. Por favor, inténtalo de nuevo.');
            }
        });
    };

    function proceedToDetailsAfterVerification() {
        const service = findServiceById(selectedServiceId);
        const adultos = parseInt($('#adultos').val()) || 0;
        const residentes = parseInt($('#residentes').val()) || 0;
        const ninos_5_12 = parseInt($('#ninos-5-12').val()) || 0;
        const ninos_menores = parseInt($('#ninos-menores').val()) || 0;

        let totalPrice = '0';
        try {
            const totalPriceElement = $('#total-price');
            if (totalPriceElement.length > 0) {
                const totalPriceText = totalPriceElement.text();
                totalPrice = totalPriceText.replace('€', '').trim();
            }
        } catch (error) {
            console.error('Error obteniendo precio total:', error);
        }

        const reservationData = {
            fecha: selectedDate,
            service_id: selectedServiceId,
            hora_ida: service.hora,
            hora_vuelta: service.hora_vuelta || '',
            adultos: adultos,
            residentes: residentes,
            ninos_5_12: ninos_5_12,
            ninos_menores: ninos_menores,
            precio_adulto: service.precio_adulto,
            precio_nino: service.precio_nino,
            precio_residente: service.precio_residente,
            total_price: totalPrice,
            descuento_grupo: $('#total-discount').text().includes('€') ?
                parseFloat($('#total-discount').text().replace('€', '').replace('-', '')) : 0,
            regla_descuento_aplicada: window.lastDiscountRule || null
        };

        console.log('Datos de reserva preparados:', reservationData);

        try {
            const dataString = JSON.stringify(reservationData);
            sessionStorage.setItem('reservationData', dataString);
            console.log('Datos guardados en sessionStorage exitosamente');
        } catch (error) {
            console.error('Error guardando en sessionStorage:', error);
            alert('Error guardando los datos de la reserva: ' + error.message);
            return;
        }

        let targetUrl;
        const currentPath = window.location.pathname;

        if (currentPath.includes('/bravo/')) {
            targetUrl = window.location.origin + '/bravo/detalles-reserva/';
        } else if (currentPath.includes('/')) {
            const pathParts = currentPath.split('/').filter(part => part !== '');
            if (pathParts.length > 0 && pathParts[0] !== 'detalles-reserva') {
                targetUrl = window.location.origin + '/' + pathParts[0] + '/detalles-reserva/';
            } else {
                targetUrl = window.location.origin + '/detalles-reserva/';
            }
        } else {
            targetUrl = window.location.origin + '/detalles-reserva/';
        }

        console.log('Redirigiendo a:', targetUrl);
        window.location.href = targetUrl;
    }

    window.selectDate = selectDate;
    window.findServiceById = findServiceById;

});

function processReservation() {
    console.log("=== PROCESANDO RESERVA CON REDSYS ===");

    // Verificar checkbox de privacidad
    const checkbox = document.getElementById("privacy-policy");
    if (!checkbox || !checkbox.checked) {
        alert("Debes aceptar la política de privacidad para continuar.");
        if (checkbox) checkbox.focus();
        return;
    }

    // Verificar que reservasAjax está definido
    if (typeof reservasAjax === "undefined") {
        console.error("reservasAjax no está definido");
        alert("Error: Variables AJAX no disponibles. Recarga la página e inténtalo de nuevo.");
        return;
    }

    // Validar formularios
    const nombre = document.querySelector("[name='nombre']")?.value?.trim() || '';
    const apellidos = document.querySelector("[name='apellidos']")?.value?.trim() || '';
    const email = document.querySelector("[name='email']")?.value?.trim() || '';
    const telefono = document.querySelector("[name='telefono']")?.value?.trim() || '';

    if (!nombre || !apellidos || !email || !telefono) {
        alert("Por favor, completa todos los campos de datos personales.");
        return;
    }

    // Validar email básico
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        alert("Por favor, introduce un email válido.");
        return;
    }

    // ✅ NUEVO: Validar que el teléfono tenga formato correcto
    const telefonoLimpio = telefono.replace(/[\s\-\(\)\+]/g, '');
    if (telefonoLimpio.length < 9 || !/^\d{9,15}$/.test(telefonoLimpio)) {
        alert("Por favor, introduce un número de teléfono válido (mínimo 9 dígitos).");
        return;
    }

    // ✅ NUEVO: Verificar que el teléfono no contiene un email (prevenir bug de campos cruzados)
    if (telefono.includes('@')) {
        alert("El campo teléfono contiene una dirección de email. Por favor, revisa los datos.");
        return;
    }

    // ✅ NUEVO: Verificar que el email no contiene solo números (campos intercambiados)
    if (/^\+?\d[\d\s\-\(\)]+$/.test(email)) {
        alert("El campo email contiene un número de teléfono. Por favor, revisa los datos.");
        return;
    }

    // Obtener datos de reserva desde sessionStorage
    let reservationData;
    try {
        const dataString = sessionStorage.getItem("reservationData");
        if (!dataString) {
            alert("Error: No hay datos de reserva. Por favor, vuelve a hacer la reserva.");
            window.history.back();
            return;
        }

        reservationData = JSON.parse(dataString);
        console.log("Datos de reserva recuperados:", reservationData);
    } catch (error) {
        console.error("Error parseando datos de reserva:", error);
        alert("Error en los datos de reserva. Por favor, vuelve a hacer la reserva.");
        window.history.back();
        return;
    }

    // ✅ AÑADIR DATOS PERSONALES A LA RESERVA
    reservationData.nombre = nombre;
    reservationData.apellidos = apellidos;
    reservationData.email = email;
    reservationData.telefono = telefono;

    console.log("Datos completos para Redsys:", reservationData);

    // ✅ VERIFICACIÓN FINAL: Confirmar que los campos son correctos antes de enviar
    console.log("📋 Verificación final - Email:", reservationData.email, "| Teléfono:", reservationData.telefono);
    if (reservationData.telefono.includes('@') || !reservationData.email.includes('@')) {
        console.error("❌ ALERTA: Los campos email/teléfono parecen estar cruzados");
        alert("Error: Los campos de email y teléfono parecen estar intercambiados. Por favor, revísalos.");
        return;
    }

    // Deshabilitar botón y mostrar estado de carga
    const processBtn = document.querySelector(".process-btn");
    if (processBtn) {
        const originalText = processBtn.textContent;
        processBtn.disabled = true;
        processBtn.textContent = "Redirigiendo al banco...";

        // Función para rehabilitar botón
        window.enableProcessButton = function () {
            processBtn.disabled = false;
            processBtn.textContent = originalText;
        };
    }

    // ✅ ENVIAR A REDSYS
    const requestData = {
        action: "generar_formulario_pago_redsys",
        nonce: reservasAjax.nonce,
        reservation_data: JSON.stringify(reservationData)
    };

    console.log("Enviando datos a Redsys:", requestData);

    // Enviar solicitud AJAX para generar formulario de Redsys
    fetch(reservasAjax.ajax_url, {
        method: "POST",
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams(requestData)
    })
        .then(response => {
            console.log("Response status:", response.status);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            return response.json();
        })
        .then(data => {
            console.log("Respuesta del servidor:", data);

            // Rehabilitar botón
            if (window.enableProcessButton) window.enableProcessButton();

            if (data && data.success) {
                console.log("✅ Formulario de Redsys generado correctamente");

                // ✅ INSERTAR FORMULARIO Y EJECUTAR INMEDIATAMENTE
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = data.data;
                document.body.appendChild(tempDiv);

                console.log("🏦 Formulario insertado en DOM");

                // ✅ VERIFICAR QUE EL FORMULARIO SE INSERTÓ CORRECTAMENTE
                const insertedForm = document.getElementById('formulario_redsys');
                const insertedOverlay = document.getElementById('redsys-overlay');

                if (insertedForm && insertedOverlay) {
                    console.log("✅ Elementos encontrados, formulario debe ejecutarse automáticamente");

                    // ✅ BACKUP: Si no se ejecuta automáticamente en 3 segundos, forzar envío
                    setTimeout(() => {
                        if (document.getElementById('redsys-overlay')) {
                            console.log("⚠️ Ejecutando envío manual de respaldo...");
                            insertedForm.submit();
                        }
                    }, 3000);
                } else {
                    console.error("❌ No se encontraron elementos del formulario después de insertar");
                    alert("Error procesando el pago. Por favor, inténtalo de nuevo.");
                }

            } else {
                console.error("❌ Error generando formulario Redsys:", data);
                const errorMsg = data && data.data ? data.data : "Error generando formulario de pago";
                alert("Error: " + errorMsg);
            }
        })
        .catch(error => {
            console.error("❌ Error de conexión:", error);

            // Rehabilitar botón
            if (window.enableProcessButton) window.enableProcessButton();

            let errorMessage = "Error de conexión al generar el formulario de pago.";
            if (error.message.includes('403')) {
                errorMessage += " (Error 403: Acceso denegado)";
            } else if (error.message.includes('404')) {
                errorMessage += " (Error 404: URL no encontrada)";
            } else if (error.message.includes('500')) {
                errorMessage += " (Error 500: Error interno del servidor)";
            }

            errorMessage += "\n\nPor favor, inténtalo de nuevo.";
            alert(errorMessage);
        });
}


function goBackToBooking() {
    sessionStorage.removeItem("reservationData");
    window.history.back();
}