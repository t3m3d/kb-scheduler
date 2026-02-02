<?php
/*
Plugin Name: KB Scheduler
Description: Custom scheduling system for technical support and consulting.
Version: 1.5
*/

function kb_scheduler_shortcode() {
    ob_start();
    include plugin_dir_path(__FILE__) . 'public/scheduler.html';
    return ob_get_clean();
}
add_shortcode('kb_scheduler', 'kb_scheduler_shortcode');


function kb_scheduler_assets() {

    wp_enqueue_style(
        'flatpickr-css',
        'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css',
        array(),
        null
    );

    wp_enqueue_script(
        'flatpickr-js',
        'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.js',
        array(),
        null,
        true
    );

    wp_enqueue_style(
        'kb-scheduler-css',
        plugin_dir_url(__FILE__) . 'public/scheduler.css',
        array(),
        null
    );

    wp_enqueue_script(
        'kb-scheduler-js',
        plugin_dir_url(__FILE__) . 'public/scheduler.js',
        array('flatpickr-js'),
        null,
        true
    );

    wp_localize_script(
        'kb-scheduler-js',
        'kb_ajax',
        array('ajax_url' => admin_url('admin-ajax.php'))
    );
}
add_action('wp_enqueue_scripts', 'kb_scheduler_assets');


function kb_scheduler_create_table() {
    global $wpdb;

    $table_name      = $wpdb->prefix . 'kb_appointments';
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
        `payment_method` varchar(20) DEFAULT NULL,
        `payment_status` varchar(20) DEFAULT 'pending',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `appointment_date` (`appointment_date`)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'kb_scheduler_create_table');


add_action('wp_ajax_kb_get_booked_times', 'kb_get_booked_times');
add_action('wp_ajax_nopriv_kb_get_booked_times', 'kb_get_booked_times');

function kb_get_booked_times() {
    global $wpdb;

    $table = $wpdb->prefix . 'kb_appointments';
    $date  = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : '';

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


add_action('wp_ajax_kb_create_payment', 'kb_create_payment');
add_action('wp_ajax_nopriv_kb_create_payment', 'kb_create_payment');

function kb_create_payment() {

    $input = json_decode(file_get_contents("php://input"), true);

    if (!$input) {
        wp_send_json([
            "success" => false,
            "message" => "Invalid request."
        ]);
    }

    $service_type   = sanitize_text_field($input["service_type"] ?? '');
    $service_method = sanitize_text_field($input["service_method"] ?? '');
    $date           = sanitize_text_field($input["appointment_date"] ?? '');
    $time           = sanitize_text_field($input["appointment_time"] ?? '');
    $name           = sanitize_text_field($input["client_name"] ?? '');
    $email          = sanitize_email($input["client_email"] ?? '');
    $address        = sanitize_text_field($input["client_address"] ?? '');

    if (!$service_type || !$service_method || !$date || !$time || !$name || !$email) {
        wp_send_json([
            "success" => false,
            "message" => "Missing required fields."
        ]);
    }

    $base_prices = [
        "tech"    => 30,
        "consult" => 55
    ];

    $method_addons = [
        "phone"     => 10,
        "housecall" => 30,
        "office"    => 0
    ];

    $price = ($base_prices[$service_type] ?? 0) + ($method_addons[$service_method] ?? 0);

    global $wpdb;
    $table = $wpdb->prefix . 'kb_appointments';

    $inserted = $wpdb->insert($table, array(
        'client_name'      => $name,
        'client_email'     => $email,
        'client_address'   => $address,
        'service_type'     => $service_type,
        'service_method'   => $service_method,
        'appointment_date' => $date,
        'appointment_time' => $time,
        'payment_method'   => 'none',
        'payment_status'   => 'bypassed',
        'created_at'       => current_time('mysql')
    ));

    if (!$inserted) {
        wp_send_json([
            "success" => false,
            "message" => "Database insert failed: " . $wpdb->last_error
        ]);
    }

    $to = get_option('admin_email'); // Your WP admin email
    $subject = "New Appointment Booked";

    $message = "A new appointment has been booked:\n\n" .
               "Name: $name\n" .
               "Email: $email\n" .
               "Service: $service_type\n" .
               "Method: $service_method\n" .
               "Date: $date\n" .
               "Time: $time\n" .
               "Address: " . ($address ?: "N/A") . "\n\n" .
               "Price: $$price\n" .
               "Booked At: " . current_time('mysql') . "\n";

    wp_mail($to, $subject, $message);

    wp_send_json([
        "success" => true,
        "message" => "Appointment booked successfully.",
        "price"   => $price
    ]);
}