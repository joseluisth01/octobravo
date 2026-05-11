<?php
class OctoBookings {

    private $auth;
    private $hold_minutes = 30;

    public function __construct(OctoAuth $auth) {
        $this->auth = $auth;
    }

    public function register_routes() {
        register_rest_route('octo/v1', '/bookings', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'create_booking'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('octo/v1', '/bookings', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'list_bookings'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('octo/v1', '/bookings/(?P<uuid>[a-zA-Z0-9\-]+)', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_booking'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('octo/v1', '/bookings/(?P<uuid>[a-zA-Z0-9\-]+)/confirm', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'confirm_booking'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('octo/v1', '/bookings/(?P<uuid>[a-zA-Z0-9\-]+)/cancel', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'cancel_booking'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('octo/v1', '/bookings/(?P<uuid>[a-zA-Z0-9\-]+)/extend', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'extend_booking'),
            'permission_callback' => '__return_true',
        ));
    }

    public function create_booking(WP_REST_Request $request) {
        $auth = $this->auth->validate_request($request);
        if (is_wp_error($auth)) {
            return $this->auth->error_response(
                $auth->get_error_code(),
                $auth->get_error_message(),
                $auth->get_error_data()['status']
            );
        }

        $body = $request->get_json_params();

        if (empty($body['productId']) || empty($body['optionId']) || empty($body['availabilityId'])) {
            return $this->auth->error_response('ErrorBadRequest', 'Se requiere productId, optionId, availabilityId', 400);
        }

        if (empty($body['unitItems']) || !is_array($body['unitItems'])) {
            return $this->auth->error_response('ErrorBadRequest', 'Se requiere unitItems', 400);
        }

        $uuid = !empty($body['uuid']) ? sanitize_text_field($body['uuid']) : $this->generate_uuid();

        global $wpdb;
        $table_octo      = $wpdb->prefix . 'octo_bookings';
        $table_servicios = $wpdb->prefix . 'reservas_servicios';

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_octo WHERE uuid = %s",
            $uuid
        ));

        if ($existing) {
            $servicio = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_servicios WHERE id = %d",
                $existing->servicio_id
            ));
            return new WP_REST_Response($this->format_booking($existing, $servicio), 200);
        }

        $servicio_id = $this->decode_availability_id($body['availabilityId']);

        if (!$servicio_id) {
            return $this->auth->error_response('ErrorInvalidAvailabilityID', 'availabilityId no v��lido', 400);
        }

        $wpdb->query('START TRANSACTION');

        $servicio = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_servicios WHERE id = %d AND status = 'active' AND enabled = 1 FOR UPDATE",
            $servicio_id
        ));

        if (!$servicio) {
            $wpdb->query('ROLLBACK');
            return $this->auth->error_response('ErrorInvalidAvailabilityID', 'Servicio no encontrado', 404);
        }

        $fecha_hora_servicio = $servicio->fecha . ' ' . $servicio->hora;

        if (strtotime($fecha_hora_servicio) <= time()) {
            $wpdb->query('ROLLBACK');
            return $this->auth->error_response('ErrorBadRequest', 'Este servicio ya ha pasado', 400);
        }

        $unit_items    = $body['unitItems'];
        $plazas_needed = 0;

        foreach ($unit_items as $item) {
            switch ($item['unitId'] ?? '') {
                case 'adult':
                case 'child':
                case 'resident':
                    $plazas_needed++;
                    break;

                case 'infant':
                    break;

                default:
                    $wpdb->query('ROLLBACK');
                    return $this->auth->error_response('ErrorBadRequest', 'unitId no v��lido', 400);
            }
        }

        if ($plazas_needed === 0) {
            $wpdb->query('ROLLBACK');
            return $this->auth->error_response('ErrorBadRequest', 'Debe haber al menos una persona con plaza', 400);
        }

        if (intval($servicio->plazas_disponibles) < $plazas_needed) {
            $wpdb->query('ROLLBACK');
            return $this->auth->error_response(
                'ErrorBadRequest',
                'Solo quedan ' . $servicio->plazas_disponibles . ' plazas. Solicitadas: ' . $plazas_needed,
                409
            );
        }

        $wpdb->query($wpdb->prepare(
            "UPDATE $table_servicios SET plazas_disponibles = plazas_disponibles - %d WHERE id = %d",
            $plazas_needed,
            $servicio_id
        ));

        $unit_items_full = array();

        foreach ($unit_items as $item) {
            $unit_id = sanitize_text_field($item['unitId']);

            $unit_items_full[] = array(
                'uuid'    => $this->generate_uuid(),
                'unitId'  => $unit_id,
                'unit'    => array(
                    'id' => $unit_id,
                ),
                'status'  => 'ON_HOLD',
                'contact' => $item['contact'] ?? null,
                'ticket'  => null,
            );
        }

        $utc_now     = gmdate('Y-m-d H:i:s');
        $utc_expires = gmdate('Y-m-d H:i:s', time() + ($this->hold_minutes * 60));

        $wpdb->insert(
            $table_octo,
            array(
                'uuid'               => sanitize_text_field($uuid),
                'availability_id'    => sanitize_text_field($body['availabilityId']),
                'servicio_id'        => intval($servicio_id),
                'product_id'         => sanitize_text_field($body['productId']),
                'option_id'          => sanitize_text_field($body['optionId']),
                'status'             => 'ON_HOLD',
                'reseller_reference' => !empty($body['resellerReference']) ? sanitize_text_field($body['resellerReference']) : null,
                'unit_items'         => wp_json_encode($unit_items_full),
                'contact'            => wp_json_encode($body['contact'] ?? array()),
                'utc_created_at'     => $utc_now,
                'utc_expires_at'     => $utc_expires,
                'test_mode'          => !empty($auth['test_mode']) ? 1 : 0,
            ),
            array(
                '%s',
                '%s',
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
            )
        );

        if ($wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            error_log('OCTO INSERT ERROR: ' . $wpdb->last_error);
            return $this->auth->error_response('ErrorInternalServerError', 'DB Error: ' . $wpdb->last_error, 500);
        }

        $wpdb->query('COMMIT');

        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_octo WHERE uuid = %s",
            $uuid
        ));

        return new WP_REST_Response($this->format_booking($booking, $servicio), 201);
    }

    public function confirm_booking(WP_REST_Request $request) {
        $auth = $this->auth->validate_request($request);
        if (is_wp_error($auth)) {
            return $this->auth->error_response(
                $auth->get_error_code(),
                $auth->get_error_message(),
                $auth->get_error_data()['status']
            );
        }

        $uuid = sanitize_text_field($request['uuid']);

        global $wpdb;
        $table_octo      = $wpdb->prefix . 'octo_bookings';
        $table_reservas  = $wpdb->prefix . 'reservas_reservas';
        $table_servicios = $wpdb->prefix . 'reservas_servicios';

        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_octo WHERE uuid = %s",
            $uuid
        ));

        if (!$booking) {
            return $this->auth->error_response('ErrorBadRequest', 'Booking no encontrado', 404);
        }

        $servicio = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_servicios WHERE id = %d",
            $booking->servicio_id
        ));

        if ($booking->status === 'CONFIRMED') {
            return new WP_REST_Response($this->format_booking($booking, $servicio), 200);
        }

        if ($booking->status === 'EXPIRED') {
            return $this->auth->error_response('ErrorBadRequest', 'El hold ha expirado', 409);
        }

        if ($booking->status === 'CANCELLED') {
            return $this->auth->error_response('ErrorBadRequest', 'El booking est�� cancelado', 409);
        }

        $contact    = json_decode($booking->contact, true) ?? array();
        $unit_items = json_decode($booking->unit_items, true) ?? array();

        $adultos    = 0;
        $ninos      = 0;
        $infants    = 0;
        $residentes = 0;

        foreach ($unit_items as $item) {
            switch ($item['unitId']) {
                case 'adult':
                    $adultos++;
                    break;

                case 'child':
                    $ninos++;
                    break;

                case 'infant':
                    $infants++;
                    break;

                case 'resident':
                    $residentes++;
                    break;
            }
        }

        $total_personas = $adultos + $ninos + $residentes;

        $precio_base = 0;

        if ($servicio) {
            $precio_base += $adultos * floatval($servicio->precio_adulto);
            $precio_base += $ninos * floatval($servicio->precio_nino);
            $precio_base += $residentes * floatval($servicio->precio_residente);
        }

        $localizador = $this->generate_localizador();

        $body = $request->get_json_params() ?? array();

        $reserva_data = array(
            'localizador'      => $localizador,
            'servicio_id'      => intval($booking->servicio_id),
            'fecha'            => $servicio ? $servicio->fecha : '',
            'hora'             => $servicio ? $servicio->hora : '',
            'hora_vuelta'      => $servicio ? $servicio->hora_vuelta : null,
            'nombre'           => sanitize_text_field($contact['firstName'] ?? $body['contact']['firstName'] ?? 'OCTO'),
            'apellidos'        => sanitize_text_field($contact['lastName'] ?? $body['contact']['lastName'] ?? 'Booking'),
            'email'            => sanitize_email($contact['emailAddress'] ?? $body['contact']['emailAddress'] ?? ''),
            'telefono'         => sanitize_text_field($contact['phoneNumber'] ?? $body['contact']['phoneNumber'] ?? ''),
            'adultos'          => intval($adultos),
            'residentes'       => intval($residentes),
            'ninos_5_12'       => intval($ninos),
            'ninos_menores'    => intval($infants),
            'total_personas'   => intval($total_personas),
            'precio_base'      => floatval($precio_base),
            'descuento_total'  => 0,
            'precio_final'     => floatval($precio_base),
            'estado'           => 'confirmada',
            'metodo_pago'      => 'civitatis_octo',
            'created_at'       => current_time('mysql'),
        );

        $wpdb->insert($table_reservas, $reserva_data);

        if ($wpdb->last_error) {
            error_log('OCTO RESERVA INSERT ERROR: ' . $wpdb->last_error);
            return $this->auth->error_response('ErrorInternalServerError', 'DB Error reserva: ' . $wpdb->last_error, 500);
        }

        $reserva_id = $wpdb->insert_id;

        $unit_items_confirmed = array();

        foreach ($unit_items as $item) {
            $item['status'] = 'CONFIRMED';
            $item['ticket'] = array(
                'redemptionMethod' => 'DIGITAL',
                'utcRedeemedAt'    => null,
                'deliveryOptions'  => array(
                    array(
                        'deliveryFormat' => 'QRCODE',
                        'deliveryValue'  => $localizador . '-' . $item['uuid'],
                    ),
                ),
            );

            $unit_items_confirmed[] = $item;
        }

        $utc_confirmed = gmdate('Y-m-d H:i:s');

        $wpdb->update(
            $table_octo,
            array(
                'status'             => 'CONFIRMED',
                'supplier_reference' => $localizador,
                'reserva_id'         => intval($reserva_id),
                'unit_items'         => wp_json_encode($unit_items_confirmed),
                'utc_confirmed_at'   => $utc_confirmed,
            ),
            array(
                'uuid' => $uuid,
            )
        );

        $booking_updated = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_octo WHERE uuid = %s",
            $uuid
        ));

        return new WP_REST_Response($this->format_booking($booking_updated, $servicio), 200);
    }

    public function cancel_booking(WP_REST_Request $request) {
        $auth = $this->auth->validate_request($request);
        if (is_wp_error($auth)) {
            return $this->auth->error_response(
                $auth->get_error_code(),
                $auth->get_error_message(),
                $auth->get_error_data()['status']
            );
        }

        $uuid = sanitize_text_field($request['uuid']);

        global $wpdb;
        $table_octo      = $wpdb->prefix . 'octo_bookings';
        $table_reservas  = $wpdb->prefix . 'reservas_reservas';
        $table_servicios = $wpdb->prefix . 'reservas_servicios';

        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_octo WHERE uuid = %s",
            $uuid
        ));

        if (!$booking) {
            return $this->auth->error_response('ErrorBadRequest', 'Booking no encontrado', 404);
        }

        $servicio = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_servicios WHERE id = %d",
            $booking->servicio_id
        ));

        if ($booking->status === 'CANCELLED') {
            return new WP_REST_Response($this->format_booking($booking, $servicio), 200);
        }

        if (in_array($booking->status, array('ON_HOLD', 'CONFIRMED'), true)) {
            $unit_items = json_decode($booking->unit_items, true) ?? array();
            $plazas     = 0;

            foreach ($unit_items as $item) {
                if (($item['unitId'] ?? '') !== 'infant') {
                    $plazas++;
                }
            }

            if ($plazas > 0) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table_servicios SET plazas_disponibles = plazas_disponibles + %d WHERE id = %d",
                    $plazas,
                    $booking->servicio_id
                ));
            }

            if ($booking->reserva_id) {
                $body   = $request->get_json_params() ?? array();
                $reason = !empty($body['reason']) ? sanitize_text_field($body['reason']) : 'Sin motivo';

                $wpdb->update(
                    $table_reservas,
                    array(
                        'estado'             => 'cancelada',
                        'motivo_cancelacion' => 'Cancelaci��n v��a OCTO - ' . $reason,
                        'fecha_cancelacion'  => current_time('mysql'),
                    ),
                    array(
                        'id' => intval($booking->reserva_id),
                    )
                );
            }
        }

        $unit_items_cancelled = json_decode($booking->unit_items, true) ?? array();

        foreach ($unit_items_cancelled as &$item) {
            $item['status'] = 'CANCELLED';
            $item['ticket'] = null;
        }

        unset($item);

        $wpdb->update(
            $table_octo,
            array(
                'status'            => 'CANCELLED',
                'unit_items'        => wp_json_encode($unit_items_cancelled),
                'utc_cancelled_at'  => gmdate('Y-m-d H:i:s'),
            ),
            array(
                'uuid' => $uuid,
            )
        );

        $booking_updated = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_octo WHERE uuid = %s",
            $uuid
        ));

        return new WP_REST_Response($this->format_booking($booking_updated, $servicio), 200);
    }

    // SUSTITUYE el método extend_booking completo:
public function extend_booking(WP_REST_Request $request) {
    $auth = $this->auth->validate_request($request);
    if (is_wp_error($auth)) {
        return $this->auth->error_response(
            $auth->get_error_code(),
            $auth->get_error_message(),
            $auth->get_error_data()['status']
        );
    }

    $uuid = sanitize_text_field($request['uuid']);

    global $wpdb;
    $table_octo      = $wpdb->prefix . 'octo_bookings';
    $table_servicios = $wpdb->prefix . 'reservas_servicios';

    $booking = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_octo WHERE uuid = %s",
        $uuid
    ));

    if (!$booking || $booking->status !== 'ON_HOLD') {
        return $this->auth->error_response('ErrorBadRequest', 'Booking no encontrado o no está en ON_HOLD', 400);
    }

    $new_expires = gmdate('Y-m-d H:i:s', time() + ($this->hold_minutes * 60));

    $wpdb->update(
        $table_octo,
        array('utc_expires_at' => $new_expires),
        array('uuid' => $uuid)
    );

    $booking_updated = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_octo WHERE uuid = %s",
        $uuid
    ));

    // ← AÑADIDO: recuperar servicio igual que en el resto de métodos
    $servicio = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_servicios WHERE id = %d",
        $booking_updated->servicio_id
    ));

    return new WP_REST_Response($this->format_booking($booking_updated, $servicio), 200);
}

    public function get_booking(WP_REST_Request $request) {
        $auth = $this->auth->validate_request($request);

        if (is_wp_error($auth)) {
            return $this->auth->error_response(
                $auth->get_error_code(),
                $auth->get_error_message(),
                $auth->get_error_data()['status']
            );
        }

        $uuid = sanitize_text_field($request['uuid']);

        global $wpdb;
        $table_octo      = $wpdb->prefix . 'octo_bookings';
        $table_servicios = $wpdb->prefix . 'reservas_servicios';

        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_octo WHERE uuid = %s",
            $uuid
        ));

        if (!$booking) {
            return $this->auth->error_response('ErrorBadRequest', 'Booking no encontrado', 404);
        }

        $servicio = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_servicios WHERE id = %d",
            $booking->servicio_id
        ));

        return new WP_REST_Response($this->format_booking($booking, $servicio), 200);
    }

    // SUSTITUYE el método list_bookings completo:
public function list_bookings(WP_REST_Request $request) {
    $auth = $this->auth->validate_request($request);
    if (is_wp_error($auth)) {
        return $this->auth->error_response(
            $auth->get_error_code(),
            $auth->get_error_message(),
            $auth->get_error_data()['status']
        );
    }

    global $wpdb;
    $table_octo      = $wpdb->prefix . 'octo_bookings';
    $table_servicios = $wpdb->prefix . 'reservas_servicios';

    $conditions = array('1=1');
    $params     = array();

    $reseller_ref = $request->get_param('resellerReference');
    if ($reseller_ref) {
        $conditions[] = 'b.reseller_reference = %s';
        $params[]     = sanitize_text_field($reseller_ref);
    }

    $local_date = $request->get_param('localDate');
    if ($local_date) {
        // Unir con servicios para filtrar por fecha
        $conditions[] = 's.fecha = %s';
        $params[]     = sanitize_text_field($local_date);
    }

    $supplier_ref = $request->get_param('supplierReference');
    if ($supplier_ref) {
        $conditions[] = 'b.supplier_reference = %s';
        $params[]     = sanitize_text_field($supplier_ref);
    }

    $where = implode(' AND ', $conditions);

    $query = "SELECT b.* FROM $table_octo b
              LEFT JOIN $table_servicios s ON b.servicio_id = s.id
              WHERE $where
              ORDER BY b.utc_created_at DESC
              LIMIT 100";

    if (!empty($params)) {
        $bookings = $wpdb->get_results($wpdb->prepare($query, ...$params));
    } else {
        $bookings = $wpdb->get_results($query);
    }

    $response = array();
    foreach ($bookings as $booking) {
        $servicio = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_servicios WHERE id = %d",
            $booking->servicio_id
        ));
        $response[] = $this->format_booking($booking, $servicio);
    }

    return new WP_REST_Response($response, 200);
}

    private function format_booking($booking, $servicio = null) {
        $unit_items = json_decode($booking->unit_items, true) ?? array();
        $contact    = json_decode($booking->contact, true) ?? array();

        $precio_total = 0;

        if ($servicio) {
            foreach ($unit_items as $item) {
                switch ($item['unitId']) {
                    case 'adult':
                        $precio_total += floatval($servicio->precio_adulto) * 100;
                        break;

                    case 'child':
                        $precio_total += floatval($servicio->precio_nino) * 100;
                        break;

                    case 'resident':
                        $precio_total += floatval($servicio->precio_residente) * 100;
                        break;
                }
            }
        }

        // SUSTITUYE solo el return de format_booking (el array completo):
return array(
    'id'                => $booking->id,
    'uuid'              => $booking->uuid,
    'testMode'          => (bool) $booking->test_mode,
    'resellerReference' => $booking->reseller_reference,
    'supplierReference' => $booking->supplier_reference,
    'status'            => $booking->status,
    'cancellable'       => in_array($booking->status, array('ON_HOLD', 'CONFIRMED'), true),  // ← AÑADIDO
    'productId'         => $booking->product_id,
    'optionId'          => $booking->option_id,
    'availabilityId'    => $booking->availability_id,
    'contact'           => $contact,
    'unitItems'         => $unit_items,
    'utcCreatedAt'      => $booking->utc_created_at
        ? str_replace(' ', 'T', $booking->utc_created_at) . 'Z'
        : null,
    'utcUpdatedAt'      => $booking->utc_cancelled_at
        ? str_replace(' ', 'T', $booking->utc_cancelled_at) . 'Z'
        : ($booking->utc_confirmed_at
            ? str_replace(' ', 'T', $booking->utc_confirmed_at) . 'Z'
            : ($booking->utc_created_at
                ? str_replace(' ', 'T', $booking->utc_created_at) . 'Z'
                : null)),
    'utcExpiresAt'      => $booking->utc_expires_at
        ? str_replace(' ', 'T', $booking->utc_expires_at) . 'Z'
        : null,
    'utcConfirmedAt'    => $booking->utc_confirmed_at
        ? str_replace(' ', 'T', $booking->utc_confirmed_at) . 'Z'
        : null,
    'utcCancelledAt'    => $booking->utc_cancelled_at
        ? str_replace(' ', 'T', $booking->utc_cancelled_at) . 'Z'
        : null,
    'pricing'           => array(
        'original'          => intval($precio_total),
        'retail'            => intval($precio_total),
        'net'               => null,
        'currency'          => 'EUR',
        'currencyPrecision' => 2,
        'includedTaxes'     => array(),  // ← AÑADIDO, exigido por spec
    ),
    'cancellation'      => array(
        'refund'         => 'FULL',
        'reason'         => null,
        'utcCancelledAt' => $booking->utc_cancelled_at
            ? str_replace(' ', 'T', $booking->utc_cancelled_at) . 'Z'
            : null,
    ),
    'voucher'           => $booking->status === 'CONFIRMED' ? array(
        'redemptionMethod' => 'DIGITAL',
        'utcRedeemedAt'    => null,
        'deliveryOptions'  => array(
            array(
                'deliveryFormat' => 'QRCODE',
                'deliveryValue'  => $booking->supplier_reference,
            ),
        ),
    ) : null,
);
    }

    private function generate_uuid() {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    private function decode_availability_id($availability_id) {
        $parts = explode('_', $availability_id);

        if (count($parts) >= 3 && $parts[0] === 'avail') {
            return intval($parts[1]);
        }

        return null;
    }

    private function generate_localizador() {
        global $wpdb;

        $table_config = $wpdb->prefix . 'reservas_configuration';
        $anio         = date('Y');
        $config_key   = "ultimo_localizador_$anio";

        $ultimo = $wpdb->get_var($wpdb->prepare(
            "SELECT config_value FROM $table_config WHERE config_key = %s",
            $config_key
        ));

        $nuevo = $ultimo ? intval($ultimo) + 1 : 1;

        $wpdb->query($wpdb->prepare(
            "INSERT INTO $table_config (config_key, config_value, config_group) 
             VALUES (%s, %s, 'localizadores')
             ON DUPLICATE KEY UPDATE config_value = %s",
            $config_key,
            $nuevo,
            $nuevo
        ));

        return str_pad($nuevo, 6, '0', STR_PAD_LEFT);
    }
}