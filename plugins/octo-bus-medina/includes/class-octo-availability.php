<?php
class OctoAvailability {

    private $auth;

    public function __construct(OctoAuth $auth) {
        $this->auth = $auth;
    }

    public function register_routes() {
        // Disponibilidad por slots
        register_rest_route('octo/v1', '/availability', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'get_availability'),
            'permission_callback' => '__return_true',
        ));

        // Disponibilidad vista calendario (por días)
        register_rest_route('octo/v1', '/availability/calendar', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'get_availability_calendar'),
            'permission_callback' => '__return_true',
        ));
    }

    public function get_availability(WP_REST_Request $request) {
        $auth = $this->auth->validate_request($request);
        if (is_wp_error($auth)) {
            return $this->auth->error_response(
                $auth->get_error_code(),
                $auth->get_error_message(),
                $auth->get_error_data()['status']
            );
        }

        $body = $request->get_json_params();

        // Validar campos obligatorios
        if (empty($body['productId']) || empty($body['optionId']) ||
            empty($body['localDateStart']) || empty($body['localDateEnd'])) {
            return $this->auth->error_response(
                'ErrorBadRequest',
                'Se requiere productId, optionId, localDateStart, localDateEnd',
                400
            );
        }

        if ($body['productId'] !== 'bus-medina-azahara') {
            return $this->auth->error_response('ErrorInvalidProductID', 'Producto no encontrado', 404);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'reservas_servicios';

        $fecha_inicio = sanitize_text_field($body['localDateStart']);
        $fecha_fin    = sanitize_text_field($body['localDateEnd']);

        // Filtro opcional por unidades
        $units = $body['units'] ?? array();
        $total_solicitado = 0;
        foreach ($units as $unit) {
            if (($unit['unitId'] ?? '') !== 'infant') {
                $total_solicitado += intval($unit['quantity'] ?? 1);
            }
        }

        $servicios = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table
             WHERE fecha BETWEEN %s AND %s
             AND fecha >= %s
             AND status = 'active'
             AND enabled = 1
             ORDER BY fecha ASC, hora ASC",
            $fecha_inicio,
            $fecha_fin,
            date('Y-m-d')
        ));

        $response = array();

        foreach ($servicios as $servicio) {
            $plazas = intval($servicio->plazas_disponibles);

            // Si piden unidades concretas, filtrar por disponibilidad
            if ($total_solicitado > 0 && $plazas < $total_solicitado) {
                continue;
            }

            $status = 'AVAILABLE';
            if ($plazas === 0) {
                $status = 'SOLD_OUT';
            } elseif ($plazas <= 5) {
                $status = 'LIMITED';
            }

            $local_start = $servicio->fecha . 'T' . substr($servicio->hora, 0, 5) . ':00+02:00';
            $local_end   = $servicio->hora_vuelta
                ? $servicio->fecha . 'T' . substr($servicio->hora_vuelta, 0, 5) . ':00+02:00'
                : null;

            // availabilityId firmado con datos del servicio
            $availability_id = $this->generate_availability_id($servicio->id, $servicio->fecha, $servicio->hora);

            $slot = array(
                'id'                     => $availability_id,
                'localDateTimeStart'     => $local_start,
                'localDateTimeEnd'       => $local_end,
                'allDay'                 => false,
                'available'              => ($status !== 'SOLD_OUT'),
                'status'                 => $status,
                'vacancies'              => $plazas,
                'capacity'               => intval($servicio->plazas_totales),
                'maxUnits'               => $plazas,
                'utcCutoffAt'            => gmdate('Y-m-d\TH:i:s\Z', strtotime($servicio->fecha . ' ' . $servicio->hora) - 3600),
                'openingHours'           => array(),
                'productId'              => 'bus-medina-azahara',
                'optionId'               => 'standard',
                // Guardamos el ID real para usarlo al reservar
                '_servicio_id'           => $servicio->id,
            );

            // Añadir pricing si lo pide el reseller
            $capabilities = $this->auth->get_capabilities($request);
            if (in_array('octo/pricing', $capabilities) || in_array('pricing', $capabilities)) {
                $slot['unitPricing'] = array(
                    array(
                        'unitId'   => 'adult',
                        'retail'   => intval(floatval($servicio->precio_adulto) * 100),
                        'original' => intval(floatval($servicio->precio_adulto) * 100),
                        'currency' => 'EUR',
                        'currencyPrecision' => 2,
                    ),
                    array(
                        'unitId'   => 'child',
                        'retail'   => intval(floatval($servicio->precio_nino) * 100),
                        'original' => intval(floatval($servicio->precio_nino) * 100),
                        'currency' => 'EUR',
                        'currencyPrecision' => 2,
                    ),
                    array(
                        'unitId'   => 'resident',
                        'retail'   => intval(floatval($servicio->precio_residente) * 100),
                        'original' => intval(floatval($servicio->precio_residente) * 100),
                        'currency' => 'EUR',
                        'currencyPrecision' => 2,
                    ),
                    array(
                        'unitId'   => 'infant',
                        'retail'   => 0,
                        'original' => 0,
                        'currency' => 'EUR',
                        'currencyPrecision' => 2,
                    ),
                );
            }

            $response[] = $slot;
        }

        return new WP_REST_Response($response, 200);
    }

    public function get_availability_calendar(WP_REST_Request $request) {
        $auth = $this->auth->validate_request($request);
        if (is_wp_error($auth)) {
            return $this->auth->error_response(
                $auth->get_error_code(),
                $auth->get_error_message(),
                $auth->get_error_data()['status']
            );
        }

        $body = $request->get_json_params();

        if (empty($body['productId']) || empty($body['optionId']) ||
            empty($body['localDateStart']) || empty($body['localDateEnd'])) {
            return $this->auth->error_response(
                'ErrorBadRequest',
                'Se requiere productId, optionId, localDateStart, localDateEnd',
                400
            );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'reservas_servicios';

        $fecha_inicio = sanitize_text_field($body['localDateStart']);
        $fecha_fin    = sanitize_text_field($body['localDateEnd']);

        $servicios = $wpdb->get_results($wpdb->prepare(
            "SELECT fecha,
                    SUM(plazas_disponibles) as total_disponibles,
                    MIN(plazas_disponibles) as min_disponibles,
                    COUNT(*) as num_servicios
             FROM $table
             WHERE fecha BETWEEN %s AND %s
             AND fecha >= %s
             AND status = 'active'
             AND enabled = 1
             GROUP BY fecha
             ORDER BY fecha ASC",
            $fecha_inicio,
            $fecha_fin,
            date('Y-m-d')
        ));

        $response = array();

        foreach ($servicios as $dia) {
            $disponibles = intval($dia->min_disponibles);
            $status = 'AVAILABLE';
            if ($disponibles === 0) {
                $status = 'SOLD_OUT';
            } elseif ($disponibles <= 5) {
                $status = 'LIMITED';
            }

            $response[] = array(
                'localDate'  => $dia->fecha,
                'available'  => ($status !== 'SOLD_OUT'),
                'status'     => $status,
                'vacancies'  => intval($dia->total_disponibles),
                'capacity'   => null,
                'openingHours' => array(),
            );
        }

        return new WP_REST_Response($response, 200);
    }

    public function generate_availability_id($servicio_id, $fecha, $hora) {
        $data   = $servicio_id . '|' . $fecha . '|' . $hora;
        $secret = defined('AUTH_KEY') ? AUTH_KEY : 'octo_secret';
        $hash   = substr(hash_hmac('sha256', $data, $secret), 0, 16);
        return 'avail_' . $servicio_id . '_' . str_replace('-', '', $fecha) . '_' . $hash;
    }

    public function decode_availability_id($availability_id) {
        // Extrae el servicio_id del availability_id
        // Formato: avail_{servicio_id}_{fecha}_{hash}
        $parts = explode('_', $availability_id);
        if (count($parts) >= 3 && $parts[0] === 'avail') {
            return intval($parts[1]);
        }
        return null;
    }
}