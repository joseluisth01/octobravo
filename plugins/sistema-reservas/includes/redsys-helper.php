<?php
/**
 * Helper para Redsys - Funciones principales
 */

require_once __DIR__ . '/redsys-api.php';

function generar_formulario_redsys($reserva_data) {
    error_log('=== INICIANDO GENERACIÓN FORMULARIO REDSYS ===');
    error_log('Datos recibidos: ' . print_r($reserva_data, true));
    
    $miObj = new RedsysAPI();

    // CONFIGURACIÓN
    if (is_production_environment()) {
        $clave = 'Q+2780shKFbG3vkPXS2+kY6RWQLQnWD9';
        $codigo_comercio = '014591697';
        $terminal = '001';
        error_log('🟢 USANDO CONFIGURACIÓN DE PRODUCCIÓN');
    } else {
        $clave = 'sq7HjrUOBfKmC576ILgskD5srU870gJ7';
        $codigo_comercio = '999008881';
        $terminal = '001';
        error_log('🟡 USANDO CONFIGURACIÓN DE PRUEBAS');
    }
    
    // ✅ DETECTAR SI ES VISITA O RESERVA NORMAL
    $is_visita = isset($reserva_data['is_visita']) && $reserva_data['is_visita'] === true;
    
    // Obtener precio
    $total_price = null;
    if ($is_visita) {
        $total_price = $reserva_data['precio_total'];
        error_log('✅ Es una VISITA GUIADA, precio: ' . $total_price . '€');
    } else {
        if (isset($reserva_data['total_price'])) {
            $total_price = $reserva_data['total_price'];
        } elseif (isset($reserva_data['precio_final'])) {
            $total_price = $reserva_data['precio_final'];
        }
    }
    
    if ($total_price) {
        $total_price = str_replace(['€', ' ', ','], ['', '', '.'], $total_price);
        $total_price = floatval($total_price);
    }
    
    if (!$total_price || $total_price <= 0) {
        throw new Exception('El importe debe ser mayor que 0. Recibido: ' . $total_price);
    }
    
    $importe = intval($total_price * 100);
    
    $timestamp = time();
    $random = rand(100, 999);
    $pedido = date('ymdHis') . str_pad($random, 3, '0', STR_PAD_LEFT);
    
    if (strlen($pedido) > 12) {
        $pedido = substr($pedido, 0, 12);
    }
    
    $miObj->setParameter("DS_MERCHANT_AMOUNT", $importe);
    $miObj->setParameter("DS_MERCHANT_ORDER", $pedido);
    $miObj->setParameter("DS_MERCHANT_MERCHANTCODE", $codigo_comercio);
    $miObj->setParameter("DS_MERCHANT_CURRENCY", "978");
    $miObj->setParameter("DS_MERCHANT_TRANSACTIONTYPE", "0");
    $miObj->setParameter("DS_MERCHANT_TERMINAL", $terminal);
    
    $base_url = home_url();
    $miObj->setParameter("DS_MERCHANT_MERCHANTURL", $base_url . '/wp-admin/admin-ajax.php?action=redsys_notification');
    
    // ✅ URLs DIFERENTES SEGÚN TIPO
    if ($is_visita) {
        $miObj->setParameter("DS_MERCHANT_URLOK", $base_url . '/confirmacion-reserva-visita/?status=ok&order=' . $pedido);
        $miObj->setParameter("DS_MERCHANT_URLKO", $base_url . '/error-pago/?status=ko&order=' . $pedido);
        error_log('✅ URLs configuradas para VISITA GUIADA');
    } else {
        $miObj->setParameter("DS_MERCHANT_URLOK", $base_url . '/confirmacion-reserva/?status=ok&order=' . $pedido);
        $miObj->setParameter("DS_MERCHANT_URLKO", $base_url . '/error-pago/?status=ko&order=' . $pedido);
    }
    
    $descripcion = $is_visita 
        ? "Visita Guiada Medina Azahara - " . ($reserva_data['fecha'] ?? date('Y-m-d'))
        : "Reserva Medina Azahara - " . ($reserva_data['fecha'] ?? date('Y-m-d'));
    $miObj->setParameter("DS_MERCHANT_PRODUCTDESCRIPTION", $descripcion);
    
    if (isset($reserva_data['nombre']) && isset($reserva_data['apellidos'])) {
        $miObj->setParameter("DS_MERCHANT_TITULAR", $reserva_data['nombre'] . ' ' . $reserva_data['apellidos']);
    }

    $params = $miObj->createMerchantParameters();
    $signature = $miObj->createMerchantSignature($clave);
    $version = "HMAC_SHA256_V1";

    $redsys_url = is_production_environment() ? 
        'https://sis.redsys.es/sis/realizarPago' :
        'https://sis-t.redsys.es:25443/sis/realizarPago';

    error_log("URL de Redsys: " . $redsys_url);
    error_log("Pedido: " . $pedido);
    error_log("Importe: " . $importe . " céntimos");
    error_log("Tipo: " . ($is_visita ? 'VISITA GUIADA' : 'RESERVA BUS'));

    // ✅ FORMULARIO LIMPIO SIN CARACTERES ESPECIALES
    $html = '<div id="redsys-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);display:flex;align-items:center;justify-content:center;z-index:99999;">';
    $html .= '<div style="background:white;padding:30px;border-radius:10px;text-align:center;max-width:400px;">';
    $html .= '<h3 class="redirigiendo"  style="margin:0 0 20px 0;color:#333;font-family: "Duran-Regular";">Redirigiendo al banco...</h3>';
    $html .= '<div class="redirigiendo"  style="margin:20px 0;font-family: "Duran-Regular";">Por favor, espere...</div>';
    $html .= '<p class="redirigiendo"  style="font-size:14px;color:#666;margin:20px 0 0 0;font-family: "Duran-Regular";">Sera redirigido automaticamente a la pasarela de pago segura.</p>';
    $html .= '</div></div>';
    $html .= '<form id="formulario_redsys" action="' . $redsys_url . '" method="POST" style="display:none;">';
    $html .= '<input type="hidden" name="Ds_SignatureVersion" value="' . $version . '">';
    $html .= '<input type="hidden" name="Ds_MerchantParameters" value="' . $params . '">';
    $html .= '<input type="hidden" name="Ds_Signature" value="' . $signature . '">';
    $html .= '</form>';
    $html .= '<script type="text/javascript">';
    $html .= 'console.log("Iniciando redireccion a Redsys...");';
    $html .= 'setTimeout(function() {';
    $html .= 'var form = document.getElementById("formulario_redsys");';
    $html .= 'if(form) { console.log("Formulario encontrado, enviando..."); form.submit(); } else { console.error("Formulario no encontrado"); alert("Error inicializando pago"); }';
    $html .= '}, 1000);';
    $html .= '</script>';

    guardar_datos_pedido($pedido, $reserva_data);
    return $html;
}

function is_production_environment() {
    // ✅ CAMBIAR A TRUE PARA ACTIVAR PRODUCCIÓN
    return true; // ← CAMBIO: false = PRUEBAS, true = PRODUCCIÓN
}


/**
 * Guardar datos del pedido en múltiples ubicaciones para redundancia
 */
function guardar_datos_pedido($order_id, $reserva_data) {
    error_log('=== GUARDANDO DATOS DEL PEDIDO (MEJORADO) ===');
    error_log("Order ID: $order_id");
    
    // Método 1: Session (si está disponible)
    if (!session_id() && !headers_sent()) {
        session_start();
    }
    
    if (session_id()) {
        if (!isset($_SESSION['pending_orders'])) {
            $_SESSION['pending_orders'] = array();
        }
        $_SESSION['pending_orders'][$order_id] = $reserva_data;
        error_log('✅ Guardado en sesión');
    }
    
    // Método 2: Transient (24 horas)
    set_transient('redsys_order_' . $order_id, $reserva_data, 24 * HOUR_IN_SECONDS);
    error_log('✅ Guardado en transient (24h)');
    
    // Método 3: Option (backup)
    update_option('pending_order_' . $order_id, $reserva_data, 'no');
    error_log('✅ Guardado en option (24h)');
    
    // Método 4: Base de datos (más confiable)
    global $wpdb;
    $table_pending = $wpdb->prefix . 'reservas_pending_orders';
    
    // Verificar que la tabla existe
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_pending'") == $table_pending) {
        $result = $wpdb->replace(
            $table_pending,
            array(
                'order_id' => $order_id,
                'order_data' => json_encode($reserva_data),
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s')
        );
        
        if ($result !== false) {
            error_log('✅ Guardado en BD');
        } else {
            error_log('❌ Error guardando en BD: ' . $wpdb->last_error);
        }
    } else {
        error_log('⚠️ Tabla de pedidos pendientes no existe');
    }
    
    // Programar limpieza después de 24 horas
    wp_schedule_single_event(time() + (24 * HOUR_IN_SECONDS), 'delete_pending_order', array($order_id));
    
    error_log('✅ Datos guardados en 4 ubicaciones para order: ' . $order_id);
}

/**
 * Recuperar datos del pedido desde múltiples ubicaciones
 */
function recuperar_datos_pedido($order_id) {
    error_log('=== RECUPERANDO DATOS DEL PEDIDO ===');
    error_log("Order ID: $order_id");
    
    // Intento 1: Session
    if (session_id() && isset($_SESSION['pending_orders'][$order_id])) {
        error_log('✅ Datos encontrados en sesión');
        return $_SESSION['pending_orders'][$order_id];
    }
    
    // Intento 2: Transient
    $data = get_transient('redsys_order_' . $order_id);
    if ($data !== false) {
        error_log('✅ Datos encontrados en transient');
        return $data;
    }
    
    // Intento 3: Option
    $data = get_option('pending_order_' . $order_id);
    if ($data !== false) {
        error_log('✅ Datos encontrados en option');
        return $data;
    }
    
    // Intento 4: Base de datos
    global $wpdb;
    $table_pending = $wpdb->prefix . 'reservas_pending_orders';
    
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT order_data FROM $table_pending WHERE order_id = %s",
        $order_id
    ));
    
    if ($row) {
        error_log('✅ Datos encontrados en BD');
        return json_decode($row->order_data, true);
    }
    
    error_log('❌ No se encontraron datos para order: ' . $order_id);
    return null;
}





function process_successful_payment($order_id, $redsys_params) {
    error_log('=== PROCESANDO PAGO EXITOSO ===');
    error_log("Order ID: $order_id");
    error_log('Parámetros Redsys: ' . print_r($redsys_params, true));

    // Verificar si ya existe una reserva con este order_id
    global $wpdb;
    $table_reservas = $wpdb->prefix . 'reservas_reservas';
    
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table_reservas WHERE redsys_order_id = %s",
        $order_id
    ));

    if ($existing) {
        error_log("⚠️ Ya existe reserva para order_id: $order_id (ID: $existing)");
        return true; // Ya procesado, devolver éxito
    }

    // Recuperar datos del pedido
    if (!function_exists('recuperar_datos_pedido')) {
        require_once RESERVAS_PLUGIN_PATH . 'includes/redsys-helper.php';
    }
    
    $reservation_data = recuperar_datos_pedido($order_id);
    
    if (!$reservation_data) {
        error_log("❌ No se encontraron datos para order_id: $order_id");
        
        // Intentar buscar en todas las ubicaciones posibles
        error_log('🔍 Buscando en todas las ubicaciones...');
        
        // Listar todos los transients que contengan 'redsys_order'
        $transient_keys = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_redsys_order_%'"
        );
        error_log('Transients encontrados: ' . print_r($transient_keys, true));
        
     
        
        return false;
    }

    error_log('✅ Datos de reserva recuperados: ' . print_r($reservation_data, true));

    try {
        // Procesar la reserva
        if (!class_exists('ReservasProcessor')) {
            require_once RESERVAS_PLUGIN_PATH . 'includes/class-reservas-processor.php';
        }

        $processor = new ReservasProcessor();
        
        // Preparar datos para el procesador
        $payment_data = array(
            'order_id' => $order_id,
            'nombre' => $reservation_data['nombre'],
            'apellidos' => $reservation_data['apellidos'],
            'email' => $reservation_data['email'],
            'telefono' => $reservation_data['telefono'],
            'reservation_data' => json_encode($reservation_data),
            'metodo_pago' => 'redsys'
        );

        $result = $processor->process_reservation_payment($payment_data);

        if ($result['success']) {
            error_log("✅ Reserva procesada exitosamente: " . $result['data']['localizador']);
            
            // Limpiar datos temporales
            if (session_id() && isset($_SESSION['pending_orders'][$order_id])) {
                unset($_SESSION['pending_orders'][$order_id]);
            }
            delete_transient('redsys_order_' . $order_id);
            delete_option('pending_order_' . $order_id);
            
            // Limpiar de BD
            $table_pending = $wpdb->prefix . 'reservas_pending_orders';
            $wpdb->delete($table_pending, array('order_id' => $order_id));
            
            return true;
        } else {
            error_log("❌ Error procesando reserva: " . $result['message']);
            return false;
        }

    } catch (Exception $e) {
        error_log("❌ Excepción procesando pago: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return false;
    }
}