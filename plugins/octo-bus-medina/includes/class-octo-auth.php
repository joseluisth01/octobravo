<?php
class OctoAuth {

    // API keys válidas: array('key' => 'nombre_resellerrrr')
    // Añade aquí la key de Civitatis cuando te la den
    private $valid_keys = array(
        'OCTO_TEST_KEY_12345' => 'Test Reseller',
        // 'CIVITATIS_KEY_AQUI' => 'Civitatis',
    );

    public function validate_request(WP_REST_Request $request) {
        $auth_header = $request->get_header('Authorization');

        if (empty($auth_header)) {
            return new WP_Error('ErrorUnauthorized', 'Authorization header requerido', array('status' => 401));
        }

        if (substr($auth_header, 0, 7) !== 'Bearer ') {
            return new WP_Error('ErrorUnauthorized', 'Formato: Bearer {api_key}', array('status' => 401));
        }

        $api_key = trim(substr($auth_header, 7));

        if (!isset($this->valid_keys[$api_key])) {
            return new WP_Error('ErrorForbidden', 'API key inválida o desactivada', array('status' => 403));
        }

        return array(
            'api_key'  => $api_key,
            'reseller' => $this->valid_keys[$api_key],
            'test_mode' => ($api_key === 'OCTO_TEST_KEY_12345')
        );
    }

    public function send_capabilities_header($capabilities = array()) {
    $caps_string = implode(', ', $capabilities);
    header('Octo-Capabilities: ' . $caps_string);
}

    public function get_capabilities(WP_REST_Request $request) {
        $header = $request->get_header('Octo-Capabilities');
        if (empty($header)) return array();
        return array_map('trim', explode(',', $header));
    }

    public function error_response($code, $message, $status) {
        return new WP_REST_Response(
            array('error' => $code, 'errorMessage' => $message),
            $status
        );
    }

    public function add_key($api_key, $reseller_name) {
        $this->valid_keys[$api_key] = $reseller_name;
    }
}