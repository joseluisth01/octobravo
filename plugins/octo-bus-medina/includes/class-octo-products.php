<?php
class OctoProducts {

    private $auth;

    // ID fijo de tu único producto
    const PRODUCT_ID = 'bus-medina-azahara';
    const OPTION_ID  = 'standard';

    public function __construct(OctoAuth $auth) {
        $this->auth = $auth;
    }

    public function register_routes() {
        register_rest_route('octo/v1', '/products', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_products'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('octo/v1', '/products/(?P<id>[a-zA-Z0-9\-]+)', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_product'),
            'permission_callback' => '__return_true',
        ));
    }

    public function get_products(WP_REST_Request $request) {
        $auth = $this->auth->validate_request($request);
        if (is_wp_error($auth)) {
            return $this->auth->error_response(
                $auth->get_error_code(),
                $auth->get_error_message(),
                $auth->get_error_data()['status']
            );
        }

        return new WP_REST_Response(array($this->build_product()), 200);
    }

    public function get_product(WP_REST_Request $request) {
        $auth = $this->auth->validate_request($request);
        if (is_wp_error($auth)) {
            return $this->auth->error_response(
                $auth->get_error_code(),
                $auth->get_error_message(),
                $auth->get_error_data()['status']
            );
        }

        if ($request['id'] !== self::PRODUCT_ID) {
            return $this->auth->error_response('ErrorInvalidProductID', 'Producto no encontrado', 404);
        }

        return new WP_REST_Response($this->build_product(), 200);
    }

    public function build_product() {
        return array(
            'id'               => self::PRODUCT_ID,
            'internalName'     => 'Bus Medina Azahara - Córdoba',
            'reference'        => null,
            'locale'           => 'es-ES',
            'timeZone'         => 'Europe/Madrid',
            'allowFreesale'    => false,
            'instantConfirmation' => true,
            'availabilityType' => 'START_TIME',
            'deliveryFormats'  => array('QRCODE'),
            'deliveryMethods'  => array('TICKET'),
            'redemptionMethod' => 'DIGITAL',
            'options'          => array($this->build_option()),
        );
    }

    private function build_option() {
        return array(
            'id'                          => self::OPTION_ID,
            'default'                     => true,
            'internalName'                => 'Estándar',
            'reference'                   => null,
            'availabilityLocalStartTimes' => array(), // dinámico según calendario
            'cancellationCutoff'          => 'end of day',
            'cancellationCutoffAmount'    => 1,
            'cancellationCutoffUnit'      => 'day',
            'requiredContactFields'       => array('firstName', 'lastName', 'emailAddress', 'phoneNumber'),
            'units'                       => array(
                array(
                    'id'           => 'adult',
                    'internalName' => 'Adulto',
                    'reference'    => null,
                    'type'         => 'ADULT',
                    'restrictions' => array(
                        'minAge'        => 13,
                        'maxAge'        => 99,
                        'idRequired'    => false,
                        'paxCount'      => 1,
                        'accompaniedBy' => array(),
                    ),
                ),
                array(
                    'id'           => 'child',
                    'internalName' => 'Niño (5-12 años)',
                    'reference'    => null,
                    'type'         => 'CHILD',
                    'restrictions' => array(
                        'minAge'        => 5,
                        'maxAge'        => 12,
                        'idRequired'    => false,
                        'paxCount'      => 1,
                        'accompaniedBy' => array('adult'),
                    ),
                ),
                array(
                    'id'           => 'infant',
                    'internalName' => 'Niño menor de 5 años (gratis)',
                    'reference'    => null,
                    'type'         => 'INFANT',
                    'restrictions' => array(
                        'minAge'        => 0,
                        'maxAge'        => 4,
                        'idRequired'    => false,
                        'paxCount'      => 1,
                        'accompaniedBy' => array('adult'),
                    ),
                ),
                array(
                    'id'           => 'resident',
                    'internalName' => 'Residente Córdoba',
                    'reference'    => null,
                    'type'         => 'OTHER',
                    'restrictions' => array(
                        'minAge'        => 13,
                        'maxAge'        => 99,
                        'idRequired'    => true,
                        'paxCount'      => 1,
                        'accompaniedBy' => array(),
                    ),
                ),
            ),
        );
    }
}