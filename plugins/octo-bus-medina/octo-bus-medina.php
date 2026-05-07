<?php
/**
 * Plugin Name: OCTO Bus Medina Azahara
 * Description: Adaptador OCTO para conectar con Civitatis y otros resellers
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;

define('OCTO_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('OCTO_VERSION', '1.0.0');

// Crear tabla al activar
register_activation_hook(__FILE__, 'octo_activate');
function octo_activate() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table = $wpdb->prefix . 'octo_bookings';

    $sql = "CREATE TABLE $table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        uuid varchar(36) NOT NULL,
        availability_id varchar(100) NOT NULL,
        servicio_id mediumint(9) NOT NULL,
        product_id varchar(50) NOT NULL,
        option_id varchar(50) NOT NULL,
        status enum('ON_HOLD','CONFIRMED','CANCELLED','EXPIRED') DEFAULT 'ON_HOLD',
        reseller_reference varchar(100),
        supplier_reference varchar(20),
        reserva_id mediumint(9) NULL,
        unit_items longtext,
        contact longtext,
        utc_created_at datetime NOT NULL,
        utc_expires_at datetime NOT NULL,
        utc_confirmed_at datetime NULL,
        utc_cancelled_at datetime NULL,
        test_mode tinyint(1) DEFAULT 0,
        PRIMARY KEY (id),
        KEY uuid (uuid),
        KEY servicio_id (servicio_id),
        KEY status (status),
        KEY utc_expires_at (utc_expires_at)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Eliminar tabla al desactivar
register_deactivation_hook(__FILE__, 'octo_deactivate');
function octo_deactivate() {
    wp_clear_scheduled_hook('octo_expire_holds');
}

// Cargar clases
add_action('plugins_loaded', 'octo_load');
function octo_load() {
    require_once OCTO_PLUGIN_PATH . 'includes/class-octo-auth.php';
    require_once OCTO_PLUGIN_PATH . 'includes/class-octo-supplier.php';
    require_once OCTO_PLUGIN_PATH . 'includes/class-octo-products.php';
    require_once OCTO_PLUGIN_PATH . 'includes/class-octo-availability.php';
    require_once OCTO_PLUGIN_PATH . 'includes/class-octo-bookings.php';
}

// Registrar rutas REST
add_action('rest_api_init', 'octo_register_routes');
function octo_register_routes() {
    require_once OCTO_PLUGIN_PATH . 'includes/class-octo-auth.php';
    require_once OCTO_PLUGIN_PATH . 'includes/class-octo-supplier.php';
    require_once OCTO_PLUGIN_PATH . 'includes/class-octo-products.php';
    require_once OCTO_PLUGIN_PATH . 'includes/class-octo-availability.php';
    require_once OCTO_PLUGIN_PATH . 'includes/class-octo-bookings.php';

    $auth         = new OctoAuth();
    $supplier     = new OctoSupplier($auth);
    $products     = new OctoProducts($auth);
    $availability = new OctoAvailability($auth);
    $bookings     = new OctoBookings($auth);

    $supplier->register_routes();
    $products->register_routes();
    $availability->register_routes();
    $bookings->register_routes();
}

// Cron para expirar holds
add_action('init', 'octo_schedule_cron');
function octo_schedule_cron() {
    if (!wp_next_scheduled('octo_expire_holds')) {
        wp_schedule_event(time(), 'octo_every_minute', 'octo_expire_holds');
    }
}

add_filter('cron_schedules', 'octo_add_cron_interval');
function octo_add_cron_interval($schedules) {
    $schedules['octo_every_minute'] = array(
        'interval' => 60,
        'display'  => 'Cada minuto (OCTO holds)'
    );
    return $schedules;
}

add_action('octo_expire_holds', 'octo_process_expired_holds');
function octo_process_expired_holds() {
    global $wpdb;
    $table_octo     = $wpdb->prefix . 'octo_bookings';
    $table_servicios = $wpdb->prefix . 'reservas_servicios';

    $expired = $wpdb->get_results(
        "SELECT * FROM $table_octo 
         WHERE status = 'ON_HOLD' 
         AND utc_expires_at <= UTC_TIMESTAMP()"
    );

    foreach ($expired as $booking) {
        // Devolver plazas al servicio
        $unit_items = json_decode($booking->unit_items, true);
        $total_plazas = 0;
        foreach ($unit_items as $item) {
            if ($item['unitId'] !== 'infant') {
                $total_plazas++;
            }
        }

        if ($total_plazas > 0) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_servicios 
                 SET plazas_disponibles = plazas_disponibles + %d 
                 WHERE id = %d",
                $total_plazas,
                $booking->servicio_id
            ));
        }

        // Marcar como expirado
        $wpdb->update(
            $table_octo,
            array('status' => 'EXPIRED'),
            array('id' => $booking->id)
        );

        error_log("OCTO: Hold expirado y plazas devueltas - UUID: {$booking->uuid}");
    }
}

add_filter('rest_post_dispatch', 'octo_add_capabilities_header', 10, 3);
function octo_add_capabilities_header($result, $server, $request) {
    if (strpos($request->get_route(), '/octo/v1') !== false) {
        $result->header('Octo-Capabilities', '');
    }
    return $result;
}