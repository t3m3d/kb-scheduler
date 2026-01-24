<?php
/*
Plugin Name: KB Scheduler
Description: Custom scheduling system for technical support and consulting.
Version: 1.5
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

    wp_enqueue_style(
        'flatpickr-css',
        'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css',
        array(),
        null
    );

    wp_enqueue_script(
        'flatpickr-js',
        'https://cdn.jsdelivr.net/npm/flatpickr',
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


// ===============================
// STEP 3 — AJAX HANDLER: CREATE PAYMENT SESSION
// ===============================
add_action("wp_ajax_kb_create_payment", "kb_create_payment");
add_action("wp_ajax_nopriv_kb_create_payment", "kb_create_payment");

function kb_create_payment() {

    $input = json_decode(file_get_contents("php://input"), true);

    if (!$input) {
        wp_send_json([ "success" => false, "message" => "Invalid request." ]);
    }

    $service_type   = sanitize_text_field($input["service_type"]);
    $service_method = sanitize_text_field($input["service_method"]);
    $date           = sanitize_text_field($input["appointment_date"]);
    $time           = sanitize_text_field($input["appointment_time"]);
    $name           = sanitize_text_field($input["client_name"]);
    $email          = sanitize_email($input["client_email"]);
    $address        = sanitize_text_field($input["client_address"]);
    $payment_method = sanitize_text_field($input["payment_method"]);

    // PRICE CALCULATION
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
    $amount_cents = $price * 100;

    // STRIPE PAYMENT FLOW
    if ($payment_method === "stripe") {

        require_once __DIR__ . "/stripe/init.php";
        \Stripe\Stripe::setApiKey("YOUR_STRIPE_SECRET_KEY");

        try {
            $session = \Stripe\Checkout\Session::create([
                "payment_method_types" => ["card"],
                "mode" => "payment",
                "line_items" => [[
                    "price_data" => [
                        "currency" => "usd",
                        "product_data" => [
                            "name" => "Appointment – $service_type ($service_method)"
                        ],
                        "unit_amount" => $amount_cents
                    ],
                    "quantity" => 1
                ]],
                "success_url" => home_url("/booking-confirmed/"),
                "cancel_url"  => home_url("/booking-cancelled/"),
                "metadata" => [
                    "service_type"   => $service_type,
                    "service_method" => $service_method,
                    "appointment_date" => $date,
                    "appointment_time" => $time,
                    "client_name"    => $name,
                    "client_email"   => $email,
                    "client_address" => $address
                ]
            ]);

            wp_send_json([
                "success" => true,
                "redirect_url" => $session->url
            ]);

        } catch (Exception $e) {
            wp_send_json([ "success" => false, "message" => $e->getMessage() ]);
        }
    }

    // PAYPAL PAYMENT FLOW
    if ($payment_method === "paypal") {

        $paypal_client_id = "YOUR_PAYPAL_CLIENT_ID";
        $paypal_secret    = "YOUR_PAYPAL_SECRET";

        $auth = base64_encode("$paypal_client_id:$paypal_secret");

        $token_response = wp_remote_post("https://api-m.paypal.com/v1/oauth2/token", [
            "headers" => [
                "Authorization" => "Basic $auth",
                "Content-Type" => "application/x-www-form-urlencoded"
            ],
            "body" => "grant_type=client_credentials"
        ]);

        $token_body = json_decode(wp_remote_retrieve_body($token_response), true);
        $access_token = $token_body["access_token"] ?? null;

        if (!$access_token) {
            wp_send_json([ "success" => false, "message" => "PayPal authentication failed." ]);
        }

        $order_response = wp_remote_post("https://api-m.paypal.com/v2/checkout/orders", [
            "headers" => [
                "Authorization" => "Bearer $access_token",
                "Content-Type" => "application/json"
            ],
            "body" => json_encode([
                "intent" => "CAPTURE",
                "purchase_units" => [[
                    "amount" => [
                        "currency_code" => "USD",
                        "value" => $price
                    ]
                ]],
                "application_context" => [
                    "return_url" => home_url("/booking-confirmed/"),
                    "cancel_url" => home_url("/booking-cancelled/")
                ]
            ])
        ]);

        $order_body = json_decode(wp_remote_retrieve_body($order_response), true);

        if (!isset($order_body["links"])) {
            wp_send_json([ "success" => false, "message" => "PayPal order creation failed." ]);
        }

        foreach ($order_body["links"] as $link) {
            if ($link["rel"] === "approve") {
                wp_send_json([
                    "success" => true,
                    "redirect_url" => $link["href"]
                ]);
            }
        }

        wp_send_json([ "success" => false, "message" => "PayPal approval link not found." ]);
    }

    wp_send_json([ "success" => false, "message" => "Invalid payment method." ]);
}



// ===============================
// STEP 4 — REGISTER WEBHOOK ROUTES
// ===============================
add_action('rest_api_init', function () {
    register_rest_route('kb/v1', '/stripe-webhook', array(
        'methods'  => 'POST',
        'callback' => 'kb_stripe_webhook_handler',
        'permission_callback' => '__return_true'
    ));

    register_rest_route('kb/v1', '/paypal-webhook', array(
        'methods'  => 'POST',
        'callback' => 'kb_paypal_webhook_handler',
        'permission_callback' => '__return_true'
    ));
});


// ===============================
// STRIPE WEBHOOK HANDLER
// ===============================
function kb_stripe_webhook_handler(WP_REST_Request $request) {
    global $wpdb;
    $table = $wpdb->prefix . 'kb_appointments';

    $payload = $request->get_body();
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

    $endpoint_secret = "YOUR_STRIPE_WEBHOOK_SECRET";

    require_once __DIR__ . "/stripe/init.php";

    try {
        $event = \Stripe\Webhook::constructEvent(
            $payload,
            $sig_header,
            $endpoint_secret
        );
    } catch (Exception $e) {
        return new WP_REST_Response("Invalid signature", 400);
    }

    if ($event->type === "checkout.session.completed") {
        $session = $event->data->object;
        $meta = $session->metadata;

        $wpdb->insert($table, array(
            'client_name'      => $meta->client_name,
            'client_email'     => $meta->client_email,
            'client_address'   => $meta->client_address,
            'service_type'     => $meta->service_type,
            'service_method'   => $meta->service_method,
            'appointment_date' => $meta->appointment_date,
            'appointment_time' => $meta->appointment_time,
            'payment_method'   => 'stripe',
            'payment_status'   => 'paid',
            'created_at'       => current_time('mysql')
        ));
    }

    return new WP_REST_Response("OK", 200);
}


// ===============================
// PAYPAL WEBHOOK HANDLER
// ===============================
function kb_paypal_webhook_handler(WP_REST_Request $request) {
    global $wpdb;
    $table = $wpdb->prefix . 'kb_appointments';

    $body = json_decode($request->get_body(), true);

    if (!isset($body['event_type']) || $body['event_type'] !== "PAYMENT.CAPTURE.COMPLETED") {
        return new WP_REST_Response("Ignored", 200);
    }

    // PayPal metadata stored in custom_id
    $meta = json_decode($body['resource']['custom_id'], true);

    if (!$meta) {
        return new WP_REST_Response("Missing metadata", 400);
    }

    $wpdb->insert($table, array(
        'client_name'      => sanitize_text_field($meta['client_name']),
        'client_email'     => sanitize_email($meta['client_email']),
        'client_address'   => sanitize_text_field($meta['client_address']),
        'service_type'     => sanitize_text_field($meta['service_type']),
        'service_method'   => sanitize_text_field($meta['service_method']),
        'appointment_date' => sanitize_text_field($meta['appointment_date']),
        'appointment_time' => sanitize_text_field($meta['appointment_time']),
        'payment_method'   => 'paypal',
        'payment_status'   => 'paid',
        'created_at'       => current_time('mysql')
    ));

    return new WP_REST_Response("OK", 200);
}