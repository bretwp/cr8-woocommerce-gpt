<?php
// Security check to prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Function to handle GPT requests
function fetch_gpt_response($user_input) {
    $api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';

    if (!$api_key) {
        error_log("OpenAI ERROR: API key is missing.");
        return "Error: OpenAI API key is missing.";
    }

    $url = "https://api.openai.com/v1/chat/completions";

    // Fetch stored WooCommerce product data
    $stored_products = get_transient('woocommerce_fetched_products');
    if (!$stored_products) {
        $stored_products = "No product data available. The user should have clicked Agent or Property first.";
    }

    $data = [
        "model" => "gpt-4-turbo",
        "messages" => [
            ["role" => "system", "content" => "You are a WooCommerce assistant. You have access to the following available products from the user's WooCommerce store:\n\n" . json_encode($stored_products, JSON_PRETTY_PRINT) . "\n\nYour job is to assist the user with selecting, comparing, and understanding these products. If a user asks about a product, assume you have full knowledge of the inventory and provide detailed answers."],
            ["role" => "user", "content" => $user_input]
        ],
        "temperature" => 0.7,
        "max_tokens" => 500
    ];

    $args = [
        "body" => json_encode($data),
        "headers" => [
            "Content-Type" => "application/json",
            "Authorization" => "Bearer " . $api_key
        ],
        "method" => "POST",
        "timeout" => 20 // Increase timeout from 5s to 20s
    ];

    error_log("Sending request to OpenAI with increased timeout...");

    $response = wp_remote_post($url, $args);

    if (is_wp_error($response)) {
        error_log("OpenAI ERROR: " . $response->get_error_message());
        return "Error: Unable to connect to OpenAI. " . $response->get_error_message();
    }

    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);

    error_log("OpenAI Raw Response: " . print_r($result, true));

    if (!isset($result['choices'][0]['message']['content'])) {
        return "Error: No valid response from GPT. " . json_encode($result);
    }

    return $result['choices'][0]['message']['content'];
}

// AJAX handler for GPT response
add_action('wp_ajax_fetch_gpt_response', 'handle_gpt_ajax_request');
add_action('wp_ajax_nopriv_fetch_gpt_response', 'handle_gpt_ajax_request');

function handle_gpt_ajax_request() {
    if (!isset($_POST['user_input']) || empty($_POST['user_input'])) {
        wp_send_json_error(['message' => 'No input provided.']);
        return;
    }

    $user_input = sanitize_text_field($_POST['user_input']);
    $response = fetch_gpt_response($user_input);

    if ($response) {
        wp_send_json_success(['response' => $response]);
    } else {
        wp_send_json_error(['message' => 'Failed to fetch GPT response.']);
    }
}

// AJAX handler to store WooCommerce products
add_action('wp_ajax_store_woocommerce_products', 'store_woocommerce_products');
add_action('wp_ajax_nopriv_store_woocommerce_products', 'store_woocommerce_products');

function store_woocommerce_products() {
    if (!isset($_POST['products'])) {
        error_log("ERROR: No product data provided.");
        wp_send_json_error(['message' => 'No product data provided.']);
        return;
    }

    // Debugging: Log received data before decoding
    error_log("Received Product Data: " . print_r($_POST['products'], true));

    // Decode JSON product data
    $products = json_decode(stripslashes($_POST['products']), true);

    if (!$products || !is_array($products)) {
        error_log("ERROR: Invalid product data format.");
        wp_send_json_error(['message' => 'Invalid product data format.']);
        return;
    }

    // Store WooCommerce products in a WordPress transient for GPT access
    set_transient('woocommerce_fetched_products', $products, 3600); // Store for 1 hour

    error_log("SUCCESS: Products stored successfully.");
    wp_send_json_success(['message' => 'Products stored successfully.']);
}

// Enqueue chatbot UI script and pass AJAX URL to JavaScript
function chatbot_enqueue_scripts() {
    wp_enqueue_script('chatbot-ui', plugin_dir_url(__FILE__) . 'chatbot-ui.js', array('jquery'), null, true);

    // Ensure chatbot_ajax is available in JavaScript
    wp_localize_script('chatbot-ui', 'chatbot_ajax', ['ajax_url' => admin_url('admin-ajax.php')]);
}
add_action('wp_enqueue_scripts', 'chatbot_enqueue_scripts');