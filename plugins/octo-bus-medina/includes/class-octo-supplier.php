<?php
class OctoSupplier {

    private $auth;

    public function __construct(OctoAuth $auth) {
        $this->auth = $auth;
    }

    public function register_routes() {
        register_rest_route('octo/v1', '/supplier', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_supplier'),
            'permission_callback' => '__return_true',
        ));
    }

    public function get_supplier(WP_REST_Request $request) {
        $auth = $this->auth->validate_request($request);
        if (is_wp_error($auth)) {
            return $this->auth->error_response(
                $auth->get_error_code(),
                $auth->get_error_message(),
                $auth->get_error_data()['status']
            );
        }

        return new WP_REST_Response(array(
            'id'       => 'autocares-bravo',
            'name'     => 'Autocares Bravo - Bus Medina Azahara',
            'endpoint' => rest_url('octo/v1'),
            'contact'  => array(
                'website' => 'https://autobusmedinaazahara.com',
                'email'   => 'fbravo@autocaresbravo.com',
                'telephone' => '',
                'address' => 'Córdoba, España',
            ),
        ), 200);
    }
}