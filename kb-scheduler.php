<?php
/*
Plugin Name: KB Scheduler
Description: Custom scheduling system for technical support and consulting.
Version: 1.4
*/

// ===============================
// SHORTCODE
// ===============================
function kb_scheduler_shortcode() {
    ob_start();
    include plugin_dir_path(__FILE__) . 'public/scheduler.html';
    return ob_get_clean();
}
add_shortcode('kb_scheduler', 'kb_scheduler_shortcode');


// ===============================
// ASSETS (CSS + JS + AJAX URL)
// ===============================
function kb_scheduler_assets() {

    // Flatpickr CSS
    wp_enqueue_style(
        'flatpickr-css',
        'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css',
        array(),
        null
    );

    // Flatpickr JS
    wp_enqueue_script(
        'flatpickr-js',
        'https://cdn.jsdelivr.net/npm/flatpickr',
        array(),
        null,
        true
    );

    // Scheduler CSS
    wp_enqueue_style(
        'kb-scheduler-css',
        plugin_dir_url(__FILE__) . 'public/scheduler.css',
        array(),
        null
    );

    // Scheduler JS
    wp_enqueue_script(
        'kb-scheduler-js',
        plugin_dir_url(__FILE__) . 'public/scheduler.js',
        array('flatpickr-js'),
        null,
        true
    );

    // Localize AJAX URL
    wp_localize_script(
        'kb-scheduler-js',
        'kb_ajax',
        array('ajax_url' => admin_url('admin-ajax.php'))
    );
}
add_action('wp_enqueue_scripts', 'kb_scheduler_assets');


// ===============================
// DATABASE TABLE ON ACTIVATION
// ===============================
function kb_scheduler_create_table() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'kb_appointments';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE `$table_name` (
        `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        `client_name` varchar(191) NOT NULL,
        `client_email` varchar(191) NOT NULL,
        `client_address` text NULL,
        `service_type` varchar(50) NOT NULL,
        `service_method` varchar(50) NOT NULL,
        `appointment_date` date NOT NULL,
        `appointment_time` varchar(20) NOT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `appointment_date` (`appointment_date`)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'kb_scheduler_create_table');


// ===============================
// AJAX HANDLER — SAVE APPOINTMENT
// ===============================
add_action('wp_ajax_kb_save_appointment', 'kb_save_appointment');
add_action('wp_ajax_nopriv_kb_save_appointment', 'kb_save_appointment');

function kb_save_appointment() {
    global $wpdb;

    $table = $wpdb->prefix . 'kb_appointments';

    // Required fields
    $service_type   = isset($_POST['service_type']) ? sanitize_text_field($_POST['service_type']) : '';
    $service_method = isset($_POST['service_method']) ? sanitize_text_field($_POST['service_method']) : '';
    $date           = isset($_POST['appointment_date']) ? sanitize_text_field($_POST['appointment_date']) : '';
    $time           = isset($_POST['appointment_time']) ? sanitize_text_field($_POST['appointment_time']) : '';

    // Match HTML field names exactly
    $client_name    = isset($_POST['clientName']) ? sanitize_text_field($_POST['clientName']) : '';
    $client_email   = isset($_POST['clientEmail']) ? sanitize_email($_POST['clientEmail']) : '';
    $client_address = isset($_POST['clientAddress']) ? sanitize_textarea_field($_POST['clientAddress']) : '';

    // Validate required fields
    if (!$service_type || !$service_method || !$date || !$time || !$client_name || !$client_email) {
        wp_send_json(array(
            'success' => false,
            'message' => 'Missing required fields.'
        ));
    }

    // Insert into DB
    $wpdb->insert($table, array(
        'client_name'      => $client_name,
        'client_email'     => $client_email,
        'client_address'   => $client_address,
        'service_type'     => $service_type,
        'service_method'   => $service_method,
        'appointment_date' => $date,
        'appointment_time' => $time,
        'created_at'       => current_time('mysql')
    ));

    wp_send_json(array('success' => true));
}


// ===============================
// AJAX HANDLER — GET BOOKED TIMES
// ===============================
add_action('wp_ajax_kb_get_booked_times', 'kb_get_booked_times');
add_action('wp_ajax_nopriv_kb_get_booked_times', 'kb_get_booked_times');

function kb_get_booked_times() {
    global $wpdb;

    $table = $wpdb->prefix . 'kb_appointments';
    $date  = sanitize_text_field($_GET['date']);

    if (!$date) {
        wp_send_json(array());
    }

    $results = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT appointment_time FROM $table WHERE appointment_date = %s",
            $date
        )
    );

    wp_send_json($results);
}
