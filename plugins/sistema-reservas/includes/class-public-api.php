<?php
/**
 * API Pública simplificada para Partners Externos
 */
class ReservasPublicAPI {

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        $namespace = 'reservas/v1';

        // GET /wp-json/reservas/v1/availability
        register_rest_route($namespace, '/availability', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_availability'),
            'permission_callback' => array($this, 'check_api_key'),
        ));

        // POST /wp-json/reservas/v1/booking
        register_rest_route($namespace, '/booking', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'create_booking'),
            'permission_callback' => array($this, 'check_api_key'),
        ));
    }

    /**
     * Verificar API Key en cada request
     */
    public function check_api_key(WP_REST_Request $request) {
        $api_key    = $request->get_header('X-API-Key');
        $api_secret = $request->get_header('X-API-Secret');

        if (empty($api_key) || empty($api_secret)) {
            return new WP_Error(
                'missing_credentials',
                'Se requieren las cabeceras X-API-Key y X-API-Secret',
                array('status' => 401)
            );
        }

        $partner = $this->get_partner_by_key($api_key, $api_secret);

        if (!$partner) {
            error_log("API: Intento de acceso con credenciales inválidas - Key: $api_key");
            return new WP_Error(
                'invalid_credentials',
                'Credenciales de API inválidas',
                array('status' => 401)
            );
        }

        if ($partner->status !== 'active') {
            return new WP_Error(
                'account_suspended',
                'Esta API key está suspendida',
                array('status' => 403)
            );
        }

        // Guardar partner en el request para usarlo después
        $request->set_param('_partner', $partner);
        $this->log_request($partner->id);

        return true;
    }

    /**
     * GET /availability
     * Parámetros opcionales: date_from, date_to (YYYY-MM-DD)
     * Por defecto devuelve los próximos 30 días
     */
    public function get_availability(WP_REST_Request $request) {
    global $wpdb;

    $date_from = sanitize_text_field($request->get_param('date_from') ?: date('Y-m-d'));
    $date_to   = sanitize_text_field($request->get_param('date_to')   ?: date('Y-m-d', strtotime('+30 days')));
    $hora      = sanitize_text_field($request->get_param('hora') ?: ''); // ✅ NUEVO PARÁMETRO

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
        return new WP_Error('invalid_date', 'Formato de fecha inválido. Usa YYYY-MM-DD', array('status' => 400));
    }

    if ((strtotime($date_to) - strtotime($date_from)) / 86400 > 90) {
        return new WP_Error('range_too_large', 'El rango máximo es de 90 días', array('status' => 400));
    }

    $table = $wpdb->prefix . 'reservas_servicios';

    // ✅ FILTRAR POR HORA SI SE PROPORCIONA
    $hora_condition = '';
    $params = array($date_from, $date_to, date('Y-m-d'));

    if (!empty($hora)) {
        $hora_condition = 'AND TIME(hora) = TIME(%s)';
        $params[] = $hora;
    }

    $servicios = $wpdb->get_results($wpdb->prepare(
        "SELECT id, fecha, hora, hora_vuelta, plazas_disponibles, plazas_totales,
                precio_adulto, precio_nino, precio_residente
         FROM $table
         WHERE fecha BETWEEN %s AND %s
           AND fecha >= %s
           AND status = 'active'
           AND enabled = 1
           AND plazas_disponibles > 0
           $hora_condition
         ORDER BY fecha, hora",
        ...$params
    ));

    $response = array();
    foreach ($servicios as $s) {
        $response[] = array(
            'service_id'      => (int) $s->id,
            'date'            => $s->fecha,
            'departure_time'  => substr($s->hora, 0, 5),
            'return_time'     => $s->hora_vuelta ? substr($s->hora_vuelta, 0, 5) : null,
            'available_seats' => (int) $s->plazas_disponibles,
            'total_seats'     => (int) $s->plazas_totales,
            'is_available'    => (int) $s->plazas_disponibles > 0, // ✅ BOOL DE DISPONIBILIDAD
            'prices'          => array(
                'adult'    => (float) $s->precio_adulto,
                'child'    => (float) $s->precio_nino,
                'resident' => (float) $s->precio_residente,
                'infant'   => 0.00,
            ),
            'currency' => 'EUR',
        );
    }

    $partner = $request->get_param('_partner');
    error_log("API AVAILABILITY: Partner '{$partner->partner_name}' - {$date_from} a {$date_to}" . ($hora ? " hora:{$hora}" : '') . " - " . count($response) . " servicios");

    return rest_ensure_response(array(
        'success'      => true,
        'count'        => count($response),
        'is_available' => count($response) > 0, // ✅ DISPONIBILIDAD GENERAL
        'data'         => $response,
    ));
}

public function create_booking(WP_REST_Request $request) {
    global $wpdb;

    $partner = $request->get_param('_partner');
    $body    = $request->get_json_params();

    if (empty($body['service_id']) && (empty($body['date']) || empty($body['hora']))) {
        return new WP_Error('missing_params', 'Se requiere service_id O (date + hora)', array('status' => 400));
    }

    // ✅ PERMITIR BUSCAR POR FECHA+HORA SI NO SE MANDA SERVICE_ID
    $service_id = null;

    if (!empty($body['service_id'])) {
        $service_id = intval($body['service_id']);
    } else {
        // Buscar el servicio por fecha y hora
        $table = $wpdb->prefix . 'reservas_servicios';
        $date  = sanitize_text_field($body['date']);
        $hora  = sanitize_text_field($body['hora']);

        $servicio_encontrado = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table 
             WHERE fecha = %s AND TIME(hora) = TIME(%s) 
             AND status = 'active' AND enabled = 1",
            $date, $hora
        ));

        if (!$servicio_encontrado) {
            return new WP_Error('service_not_found', "No hay servicio disponible para {$date} a las {$hora}", array('status' => 404));
        }

        $service_id = intval($servicio_encontrado);
    }

    if (empty($body['seats']) || intval($body['seats']) < 1) {
        return new WP_Error('missing_seats', 'seats es obligatorio y debe ser mayor que 0', array('status' => 400));
    }

    $seats              = intval($body['seats']);
    $partner_booking_id = sanitize_text_field($body['partner_booking_id'] ?? '');
    $customer_name      = sanitize_text_field($body['customer_name'] ?? 'Cliente ' . $partner->partner_name);

    $table_servicios = $wpdb->prefix . 'reservas_servicios';

    $wpdb->query('START TRANSACTION');

    $servicio = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_servicios
         WHERE id = %d AND status = 'active' AND enabled = 1
         FOR UPDATE",
        $service_id
    ));

    if (!$servicio) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('service_not_found', 'Servicio no encontrado o no disponible', array('status' => 404));
    }

    if (strtotime($servicio->fecha . ' ' . $servicio->hora) <= time()) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('service_in_past', 'Este servicio ya ha pasado', array('status' => 400));
    }

    if ($servicio->plazas_disponibles < $seats) {
        $wpdb->query('ROLLBACK');
        return new WP_Error(
            'not_enough_seats',
            "Solo quedan {$servicio->plazas_disponibles} plazas. Solicitadas: {$seats}",
            array('status' => 409)
        );
    }

    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE $table_servicios
         SET plazas_disponibles = plazas_disponibles - %d
         WHERE id = %d AND plazas_disponibles >= %d",
        $seats, $service_id, $seats
    ));

    if (!$updated) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('update_failed', 'Error actualizando plazas', array('status' => 409));
    }

    $table_api = $wpdb->prefix . 'reservas_api_bookings';
    $wpdb->insert($table_api, array(
        'partner_id'         => $partner->id,
        'partner_name'       => $partner->partner_name,
        'partner_booking_id' => $partner_booking_id,
        'service_id'         => $service_id,
        'fecha'              => $servicio->fecha,
        'hora'               => $servicio->hora,
        'seats'              => $seats,
        'customer_name'      => $customer_name,
        'status'             => 'confirmed',
        'created_at'         => current_time('mysql'),
    ));

    $api_booking_id = $wpdb->insert_id;
    $wpdb->query('COMMIT');

    error_log("API BOOKING: Partner '{$partner->partner_name}' - Service:{$service_id} ({$servicio->fecha} {$servicio->hora}) - Seats:{$seats}");

    return rest_ensure_response(array(
        'success'            => true,
        'api_booking_id'     => $api_booking_id,
        'partner_booking_id' => $partner_booking_id,
        'service_id'         => $service_id,
        'date'               => $servicio->fecha,
        'departure_time'     => substr($servicio->hora, 0, 5),
        'seats_booked'       => $seats,
        'seats_remaining'    => $servicio->plazas_disponibles - $seats,
        'message'            => 'Plazas reservadas correctamente',
    ));
}

    // ─── Métodos privados ─────────────────────────────────────────────────────

    private function get_partner_by_key($api_key, $api_secret) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}reservas_api_keys WHERE api_key = %s AND api_secret = %s",
            $api_key,
            $api_secret
        ));
    }

    private function log_request($partner_id) {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}reservas_api_keys
             SET requests_today = IF(DATE(last_request) = CURDATE(), requests_today + 1, 1),
                 last_request = NOW()
             WHERE id = %d",
            $partner_id
        ));
    }
}