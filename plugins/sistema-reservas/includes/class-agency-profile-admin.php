<?php

/**
 * Clase para gestionar el perfil de agencias
 * Archivo: wp-content/plugins/sistema-reservas/includes/class-agency-profile-admin.php
 */
class ReservasAgencyProfileAdmin
{

    public function __construct()
    {
        // Hooks AJAX para gestión de perfil de agencias
        add_action('wp_ajax_get_agency_profile', array($this, 'get_agency_profile'));
        add_action('wp_ajax_nopriv_get_agency_profile', array($this, 'get_agency_profile'));

        add_action('wp_ajax_save_agency_profile', array($this, 'save_agency_profile'));
        add_action('wp_ajax_nopriv_save_agency_profile', array($this, 'save_agency_profile'));

        add_action('wp_ajax_refresh_session_data', array($this, 'refresh_session_data'));
        add_action('wp_ajax_nopriv_refresh_session_data', array($this, 'refresh_session_data'));

        add_action('wp_ajax_get_agency_visitas_config', array($this, 'get_agency_visitas_config'));
        add_action('wp_ajax_nopriv_get_agency_visitas_config', array($this, 'get_agency_visitas_config'));

        add_action('wp_ajax_toggle_visita_horario', array($this, 'toggle_visita_horario'));
        add_action('wp_ajax_nopriv_toggle_visita_horario', array($this, 'toggle_visita_horario'));

            add_action('wp_ajax_add_fecha_excluida_visita', array($this, 'add_fecha_excluida_visita'));
    add_action('wp_ajax_nopriv_add_fecha_excluida_visita', array($this, 'add_fecha_excluida_visita'));

    add_action('wp_ajax_remove_fecha_excluida_visita', array($this, 'remove_fecha_excluida_visita'));
    add_action('wp_ajax_nopriv_remove_fecha_excluida_visita', array($this, 'remove_fecha_excluida_visita'));

    }

    /**
     * Obtener datos del perfil de la agencia actual
     */
    public function get_agency_profile()
    {
        error_log('=== GET AGENCY PROFILE START ===');
        header('Content-Type: application/json');

        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'reservas_nonce')) {
                wp_send_json_error('Error de seguridad');
                return;
            }

            if (!session_id()) {
                session_start();
            }

            if (!isset($_SESSION['reservas_user'])) {
                wp_send_json_error('Sesión expirada. Recarga la página e inicia sesión nuevamente.');
                return;
            }

            $user = $_SESSION['reservas_user'];

            // Solo las agencias pueden acceder a su perfil
            if ($user['role'] !== 'agencia') {
                wp_send_json_error('Sin permisos para acceder al perfil de agencia');
                return;
            }

            global $wpdb;
            $table_name = $wpdb->prefix . 'reservas_agencies';

            $agency = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE id = %d",
                $user['id']
            ));

            if (!$agency) {
                wp_send_json_error('Agencia no encontrada');
                return;
            }

            // No enviar la contraseña por seguridad
            unset($agency->password);

            error_log('✅ Agency profile loaded successfully');
            wp_send_json_success($agency);
        } catch (Exception $e) {
            error_log('❌ GET AGENCY PROFILE EXCEPTION: ' . $e->getMessage());
            wp_send_json_error('Error del servidor: ' . $e->getMessage());
        }
    }

    /**
     * Obtener configuración de visitas guiadas de la agencia
     */
    public function get_agency_visitas_config()
    {
        error_log('=== GET AGENCY VISITAS CONFIG ===');

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'reservas_nonce')) {
            wp_send_json_error('Error de seguridad');
            return;
        }

        if (!session_id()) {
            session_start();
        }

        if (!isset($_SESSION['reservas_user']) || $_SESSION['reservas_user']['role'] !== 'agencia') {
            wp_send_json_error('Sin permisos');
            return;
        }

        global $wpdb;
        $table_services = $wpdb->prefix . 'reservas_agency_services';
        $table_disabled = $wpdb->prefix . 'reservas_agency_horarios_disabled';
        $agency_id = $_SESSION['reservas_user']['id'];

        $service = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_services WHERE agency_id = %d",
            $agency_id
        ));

        if (!$service || $service->servicio_activo != 1) {
            wp_send_json_success(array(
                'has_service' => false,
                'message' => 'No tienes visitas guiadas configuradas'
            ));
            return;
        }

        // ✅ OBTENER HORARIOS DESHABILITADOS
        $disabled_horarios = $wpdb->get_results($wpdb->prepare(
            "SELECT dia, hora FROM $table_disabled WHERE agency_id = %d",
            $agency_id
        ), ARRAY_A);

        error_log('Horarios deshabilitados: ' . print_r($disabled_horarios, true));

        wp_send_json_success(array(
            'has_service' => true,
            'service' => $service,
            'disabled_horarios' => $disabled_horarios // ✅ AÑADIR ESTO
        ));
    }


    public function toggle_visita_horario()
    {
        error_log('=== TOGGLE VISITA HORARIO ===');

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'reservas_nonce')) {
            wp_send_json_error('Error de seguridad');
            return;
        }

        if (!session_id()) {
            session_start();
        }

        if (!isset($_SESSION['reservas_user']) || $_SESSION['reservas_user']['role'] !== 'agencia') {
            wp_send_json_error('Sin permisos');
            return;
        }

        $dia = sanitize_text_field($_POST['dia']);
        $hora = sanitize_text_field($_POST['hora']);
        $enable = intval($_POST['enable']); // ✅ 1 = habilitar, 0 = deshabilitar
        $agency_id = $_SESSION['reservas_user']['id'];

        global $wpdb;
        $table_disabled = $wpdb->prefix . 'reservas_agency_horarios_disabled';

        if ($enable) {
            // ✅ HABILITAR: Eliminar de la tabla de deshabilitados
            $result = $wpdb->delete(
                $table_disabled,
                array(
                    'agency_id' => $agency_id,
                    'dia' => $dia,
                    'hora' => $hora
                )
            );

            if ($result !== false) {
                wp_send_json_success("Visita de $dia a las $hora habilitada correctamente");
            } else {
                wp_send_json_error('Error habilitando la visita: ' . $wpdb->last_error);
            }
        } else {
            // ✅ DESHABILITAR: Insertar en la tabla de deshabilitados
            $result = $wpdb->insert(
                $table_disabled,
                array(
                    'agency_id' => $agency_id,
                    'dia' => $dia,
                    'hora' => $hora
                )
            );

            if ($result !== false) {
                wp_send_json_success("Visita de $dia a las $hora deshabilitada correctamente");
            } else {
                // Si falla, puede ser porque ya existe (unique key)
                if (strpos($wpdb->last_error, 'Duplicate') !== false) {
                    wp_send_json_success("Visita ya estaba deshabilitada");
                } else {
                    wp_send_json_error('Error deshabilitando la visita: ' . $wpdb->last_error);
                }
            }
        }
    }


    /**
     * Guardar cambios del perfil de la agencia
     */
    public function save_agency_profile()
    {
        error_log('=== SAVE AGENCY PROFILE START ===');
        header('Content-Type: application/json');

        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'reservas_nonce')) {
                wp_send_json_error('Error de seguridad');
                return;
            }

            if (!session_id()) {
                session_start();
            }

            if (!isset($_SESSION['reservas_user'])) {
                wp_send_json_error('Sesión expirada. Recarga la página e inicia sesión nuevamente.');
                return;
            }

            $user = $_SESSION['reservas_user'];

            if ($user['role'] !== 'agencia') {
                wp_send_json_error('Sin permisos para modificar el perfil de agencia');
                return;
            }

            global $wpdb;
            $table_name = $wpdb->prefix . 'reservas_agencies';

            // Sanitizar datos (SIN address y notes)
            $agency_name = sanitize_text_field($_POST['agency_name']);
            $contact_person = sanitize_text_field($_POST['contact_person']);
            $email = sanitize_email($_POST['email']);
            $phone = sanitize_text_field($_POST['phone']);
            $email_notificaciones = sanitize_email($_POST['email_notificaciones']);

            // ✅ CAMPOS FISCALES
            $razon_social = sanitize_text_field($_POST['razon_social']);
            $cif = sanitize_text_field($_POST['cif']);
            $domicilio_fiscal = sanitize_text_field($_POST['domicilio_fiscal']); // ✅ Ahora es input, no textarea

            // Validaciones
            if (empty($agency_name) || strlen($agency_name) < 2) {
                wp_send_json_error('El nombre de la agencia debe tener al menos 2 caracteres');
                return;
            }

            if (empty($contact_person) || strlen($contact_person) < 2) {
                wp_send_json_error('El nombre del contacto debe tener al menos 2 caracteres');
                return;
            }

            if (empty($email) || !is_email($email)) {
                wp_send_json_error('Email de contacto no válido');
                return;
            }

            if (!empty($email_notificaciones) && !is_email($email_notificaciones)) {
                wp_send_json_error('El email de notificaciones no es válido');
                return;
            }

            if (!empty($phone) && strlen($phone) < 9) {
                wp_send_json_error('El teléfono debe tener al menos 9 dígitos');
                return;
            }

            // ✅ VALIDACIONES FISCALES
            if (!empty($cif) && strlen($cif) < 8) {
                wp_send_json_error('El CIF debe tener al menos 8 caracteres');
                return;
            }

            if (!empty($razon_social) && strlen($razon_social) < 3) {
                wp_send_json_error('La razón social debe tener al menos 3 caracteres');
                return;
            }

            // Verificar email único
            $existing_email = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE email = %s AND id != %d",
                $email,
                $user['id']
            ));

            if ($existing_email > 0) {
                wp_send_json_error('Ya existe otra agencia con ese email');
                return;
            }

            // ✅ DATOS SIN address y notes
            $update_data = array(
                'agency_name' => $agency_name,
                'contact_person' => $contact_person,
                'email' => $email,
                'phone' => $phone,
                'razon_social' => $razon_social,
                'cif' => $cif,
                'domicilio_fiscal' => $domicilio_fiscal,
                'updated_at' => current_time('mysql')
            );

            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'email_notificaciones'");
            if (!empty($column_exists)) {
                $update_data['email_notificaciones'] = $email_notificaciones;
            }

            // Actualizar en la base de datos
            $result = $wpdb->update(
                $table_name,
                $update_data,
                array('id' => $user['id'])
            );

            if ($result === false) {
                error_log('❌ Database error updating agency profile: ' . $wpdb->last_error);
                wp_send_json_error('Error actualizando el perfil: ' . $wpdb->last_error);
                return;
            }

            // Actualizar datos de sesión
            $_SESSION['reservas_user']['agency_name'] = $agency_name;
            $_SESSION['reservas_user']['email'] = $email;

            error_log('✅ Agency profile updated successfully');
            wp_send_json_success('Perfil actualizado correctamente');
        } catch (Exception $e) {
            error_log('❌ SAVE AGENCY PROFILE EXCEPTION: ' . $e->getMessage());
            wp_send_json_error('Error del servidor: ' . $e->getMessage());
        }
    }

    /**
     * Refrescar datos de sesión
     */
    public function refresh_session_data()
    {
        error_log('=== REFRESH SESSION DATA START ===');
        header('Content-Type: application/json');

        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'reservas_nonce')) {
                wp_send_json_error('Error de seguridad');
                return;
            }

            if (!session_id()) {
                session_start();
            }

            if (!isset($_SESSION['reservas_user'])) {
                wp_send_json_error('Sesión expirada');
                return;
            }

            $user = $_SESSION['reservas_user'];

            // Solo para agencias
            if ($user['role'] !== 'agencia') {
                wp_send_json_error('Sin permisos');
                return;
            }

            global $wpdb;
            $table_name = $wpdb->prefix . 'reservas_agencies';

            // Obtener datos actualizados
            $agency = $wpdb->get_row($wpdb->prepare(
                "SELECT id, username, agency_name, email, commission_percentage, max_credit_limit, current_balance, status FROM $table_name WHERE id = %d",
                $user['id']
            ));

            if ($agency) {
                // Actualizar datos de sesión
                $_SESSION['reservas_user'] = array(
                    'id' => $agency->id,
                    'username' => $agency->username,
                    'agency_name' => $agency->agency_name,
                    'email' => $agency->email,
                    'role' => 'agencia',
                    'commission_percentage' => $agency->commission_percentage,
                    'max_credit_limit' => $agency->max_credit_limit,
                    'current_balance' => $agency->current_balance,
                    'status' => $agency->status,
                    'user_type' => 'agency',
                    'login_time' => $user['login_time'] // Mantener tiempo de login original
                );

                error_log('✅ Session data refreshed successfully');
                wp_send_json_success('Datos de sesión actualizados');
            } else {
                wp_send_json_error('Agencia no encontrada');
            }
        } catch (Exception $e) {
            error_log('❌ REFRESH SESSION DATA EXCEPTION: ' . $e->getMessage());
            wp_send_json_error('Error del servidor: ' . $e->getMessage());
        }
    }

    /**
     * Método estático para obtener datos de perfil de agencia por ID
     */
    public static function get_agency_profile_by_id($agency_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'reservas_agencies';

        $agency = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $agency_id
        ));

        if ($agency) {
            // No incluir contraseña
            unset($agency->password);
        }

        return $agency;
    }

    /**
     * Método estático para validar datos de perfil
     */
    public static function validate_profile_data($data)
    {
        $errors = array();

        // Validar nombre de agencia
        if (empty($data['agency_name']) || strlen($data['agency_name']) < 2) {
            $errors[] = 'El nombre de la agencia debe tener al menos 2 caracteres';
        }

        // Validar persona de contacto
        if (empty($data['contact_person']) || strlen($data['contact_person']) < 2) {
            $errors[] = 'El nombre del contacto debe tener al menos 2 caracteres';
        }

        // Validar email principal
        if (empty($data['email']) || !is_email($data['email'])) {
            $errors[] = 'Email de contacto no válido';
        }

        // Validar email de notificaciones si está presente
        if (!empty($data['email_notificaciones']) && !is_email($data['email_notificaciones'])) {
            $errors[] = 'El email de notificaciones no es válido';
        }

        // Validar teléfono si está presente
        if (!empty($data['phone']) && strlen($data['phone']) < 9) {
            $errors[] = 'El teléfono debe tener al menos 9 dígitos';
        }

        return array(
            'valid' => empty($errors),
            'errors' => $errors
        );
    }

    /**
     * Método estático para verificar si el email está disponible
     */
    public static function is_email_available($email, $exclude_agency_id = null)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'reservas_agencies';

        $query = "SELECT COUNT(*) FROM $table_name WHERE email = %s";
        $params = array($email);

        if ($exclude_agency_id) {
            $query .= " AND id != %d";
            $params[] = $exclude_agency_id;
        }

        $count = $wpdb->get_var($wpdb->prepare($query, $params));

        return $count == 0;
    }

    /**
     * Método estático para obtener historial de cambios (futuro)
     */
    public static function log_profile_change($agency_id, $field, $old_value, $new_value, $changed_by = null)
    {
        // Implementar sistema de logging de cambios si es necesario
        error_log("PROFILE CHANGE - Agency ID: $agency_id, Field: $field, Old: $old_value, New: $new_value, Changed by: $changed_by");
    }


    public function add_fecha_excluida_visita()
{
    error_log('=== ADD FECHA EXCLUIDA VISITA ===');

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'reservas_nonce')) {
        wp_send_json_error('Error de seguridad');
        return;
    }

    if (!session_id()) {
        session_start();
    }

    if (!isset($_SESSION['reservas_user']) || $_SESSION['reservas_user']['role'] !== 'agencia') {
        wp_send_json_error('Sin permisos');
        return;
    }

    // ✅ LOGGING DETALLADO DE LOS DATOS RECIBIDOS
    error_log('POST data recibida: ' . print_r($_POST, true));
    
    $dia = sanitize_text_field($_POST['dia'] ?? '');
    $hora = sanitize_text_field($_POST['hora'] ?? '');
    $fecha = sanitize_text_field($_POST['fecha'] ?? '');
    $agency_id = $_SESSION['reservas_user']['id'];

    error_log('Datos después de sanitizar:');
    error_log('- dia: "' . $dia . '" (longitud: ' . strlen($dia) . ')');
    error_log('- hora: "' . $hora . '" (longitud: ' . strlen($hora) . ')');
    error_log('- fecha: "' . $fecha . '"');
    error_log('- agency_id: ' . $agency_id);

    // ✅ VALIDACIONES MEJORADAS
    if (empty($dia)) {
        error_log('❌ ERROR: El día está vacío');
        wp_send_json_error('El día de la semana no fue recibido correctamente. Por favor, recarga la página e intenta de nuevo.');
        return;
    }

    if (empty($hora)) {
        error_log('❌ ERROR: La hora está vacía');
        wp_send_json_error('La hora no fue recibida correctamente. Por favor, recarga la página e intenta de nuevo.');
        return;
    }

    if (empty($fecha) || strtotime($fecha) === false) {
        error_log('❌ ERROR: Fecha inválida o vacía');
        wp_send_json_error('Fecha inválida');
        return;
    }

    // Validar que la fecha no sea pasada
    if (strtotime($fecha) < strtotime(date('Y-m-d'))) {
        error_log('❌ ERROR: Intento de excluir fecha pasada');
        wp_send_json_error('No se pueden excluir fechas pasadas');
        return;
    }

    global $wpdb;
    $table_services = $wpdb->prefix . 'reservas_agency_services';

    // Obtener servicio actual
    $service = $wpdb->get_row($wpdb->prepare(
        "SELECT fechas_excluidas, horarios_disponibles FROM $table_services WHERE agency_id = %d",
        $agency_id
    ));

    if (!$service) {
        error_log('❌ ERROR: Servicio no encontrado para agency_id: ' . $agency_id);
        wp_send_json_error('Servicio no encontrado');
        return;
    }

    // ✅ VERIFICAR QUE EL DÍA Y HORA EXISTEN EN LOS HORARIOS
    $horarios = array();
    if (!empty($service->horarios_disponibles)) {
        $horarios = json_decode($service->horarios_disponibles, true);
        if (!is_array($horarios)) {
            $horarios = array();
        }
    }

    if (!isset($horarios[$dia])) {
        error_log('❌ ERROR: El día "' . $dia . '" no existe en horarios_disponibles');
        error_log('Horarios disponibles: ' . print_r(array_keys($horarios), true));
        wp_send_json_error('El día especificado no está configurado en este servicio');
        return;
    }

    $hora_normalizada = substr($hora, 0, 5); // Asegurar formato HH:MM
    $hora_existe = false;
    foreach ($horarios[$dia] as $horario_disponible) {
        if (substr($horario_disponible, 0, 5) === $hora_normalizada) {
            $hora_existe = true;
            break;
        }
    }

    if (!$hora_existe) {
        error_log('❌ ERROR: La hora "' . $hora . '" no existe para el día "' . $dia . '"');
        error_log('Horas disponibles para ' . $dia . ': ' . print_r($horarios[$dia], true));
        wp_send_json_error('La hora especificada no está configurada para este día');
        return;
    }

    // Parsear fechas excluidas existentes
    $fechas_excluidas = array();
    if (!empty($service->fechas_excluidas)) {
        try {
            $fechas_excluidas = json_decode($service->fechas_excluidas, true);
            if (!is_array($fechas_excluidas)) {
                $fechas_excluidas = array();
            }
        } catch (Exception $e) {
            error_log('Error parseando fechas excluidas: ' . $e->getMessage());
            $fechas_excluidas = array();
        }
    }

    // Inicializar array del día si no existe
    if (!isset($fechas_excluidas[$dia])) {
        $fechas_excluidas[$dia] = array();
    }

    // Verificar si la fecha ya está excluida
    if (in_array($fecha, $fechas_excluidas[$dia])) {
        error_log('⚠️ AVISO: Fecha ya excluida: ' . $fecha . ' para ' . $dia);
        wp_send_json_error('Esta fecha ya está excluida');
        return;
    }

    // Añadir la nueva fecha
    $fechas_excluidas[$dia][] = $fecha;

    // Ordenar fechas
    sort($fechas_excluidas[$dia]);

    error_log('Fechas excluidas actualizadas para ' . $dia . ': ' . print_r($fechas_excluidas[$dia], true));

    // Guardar en BD
    $fechas_json = json_encode($fechas_excluidas, JSON_UNESCAPED_UNICODE);
    error_log('JSON a guardar: ' . $fechas_json);

    $result = $wpdb->update(
        $table_services,
        array('fechas_excluidas' => $fechas_json),
        array('agency_id' => $agency_id)
    );

    if ($result !== false) {
        error_log("✅ Fecha excluida añadida correctamente: $fecha para $dia");
        wp_send_json_success("Fecha $fecha excluida correctamente para $dia");
    } else {
        error_log('❌ Error actualizando fechas excluidas en BD: ' . $wpdb->last_error);
        wp_send_json_error('Error guardando la fecha excluida: ' . $wpdb->last_error);
    }
}

/**
 * ✅ ELIMINAR FECHA EXCLUIDA
 */
public function remove_fecha_excluida_visita()
{
    error_log('=== REMOVE FECHA EXCLUIDA VISITA ===');

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'reservas_nonce')) {
        wp_send_json_error('Error de seguridad');
        return;
    }

    if (!session_id()) {
        session_start();
    }

    if (!isset($_SESSION['reservas_user']) || $_SESSION['reservas_user']['role'] !== 'agencia') {
        wp_send_json_error('Sin permisos');
        return;
    }

    $dia = sanitize_text_field($_POST['dia']);
    $hora = sanitize_text_field($_POST['hora']);
    $fecha = sanitize_text_field($_POST['fecha']);
    $agency_id = $_SESSION['reservas_user']['id'];

    global $wpdb;
    $table_services = $wpdb->prefix . 'reservas_agency_services';

    // Obtener servicio actual
    $service = $wpdb->get_row($wpdb->prepare(
        "SELECT fechas_excluidas FROM $table_services WHERE agency_id = %d",
        $agency_id
    ));

    if (!$service) {
        wp_send_json_error('Servicio no encontrado');
        return;
    }

    // Parsear fechas excluidas existentes
    $fechas_excluidas = array();
    if (!empty($service->fechas_excluidas)) {
        try {
            $fechas_excluidas = json_decode($service->fechas_excluidas, true);
            if (!is_array($fechas_excluidas)) {
                $fechas_excluidas = array();
            }
        } catch (Exception $e) {
            error_log('Error parseando fechas excluidas: ' . $e->getMessage());
            wp_send_json_error('Error procesando fechas excluidas');
            return;
        }
    }

    // Verificar que existe el array del día
    if (!isset($fechas_excluidas[$dia]) || !is_array($fechas_excluidas[$dia])) {
        wp_send_json_error('No hay fechas excluidas para este día');
        return;
    }

    // Buscar y eliminar la fecha
    $key = array_search($fecha, $fechas_excluidas[$dia]);
    if ($key === false) {
        wp_send_json_error('La fecha no está en la lista de excluidas');
        return;
    }

    unset($fechas_excluidas[$dia][$key]);

    // Reindexar el array
    $fechas_excluidas[$dia] = array_values($fechas_excluidas[$dia]);

    // Si el array del día queda vacío, eliminarlo
    if (empty($fechas_excluidas[$dia])) {
        unset($fechas_excluidas[$dia]);
    }

    // Guardar en BD
    $result = $wpdb->update(
        $table_services,
        array('fechas_excluidas' => empty($fechas_excluidas) ? null : json_encode($fechas_excluidas)),
        array('agency_id' => $agency_id)
    );

    if ($result !== false) {
        error_log("✅ Fecha excluida eliminada: $fecha para $dia");
        wp_send_json_success("Fecha eliminada correctamente");
    } else {
        error_log('❌ Error actualizando fechas excluidas: ' . $wpdb->last_error);
        wp_send_json_error('Error eliminando la fecha excluida');
    }
}
}
