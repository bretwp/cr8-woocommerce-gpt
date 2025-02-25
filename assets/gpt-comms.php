<?php
// Security check to prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Function to handle GPT requests
function fetch_gpt_response($user_input) {
    $api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
    if (!$api_key) {
        return "Error: OpenAI API key is missing.";
    }

    $system_message = get_transient('gpt_system_message');
    $current_order = get_transient('current_order_items') ?: [];
    
    if (!$system_message) {
        return "Please select Agent or Property type first.";
    }

    $data = [
        "model" => "gpt-4-turbo",
        "messages" => [
            $system_message,
            ["role" => "system", "content" => "Current order items: " . json_encode($current_order)],
            ["role" => "user", "content" => $user_input]
        ],
        "functions" => [
            [
                "name" => "add_to_order",
                "description" => "Add an item to the current order",
                "parameters" => [
                    "type" => "object",
                    "properties" => [
                        "product_name" => [
                            "type" => "string",
                            "description" => "Exact name of the product"
                        ],
                        "quantity" => [
                            "type" => "integer",
                            "description" => "Number of items to order"
                        ]
                    ],
                    "required" => ["product_name", "quantity"]
                ]
            ],
            [
                "name" => "remove_from_order",
                "description" => "Remove an item from the current order",
                "parameters" => [
                    "type" => "object",
                    "properties" => [
                        "product_name" => [
                            "type" => "string",
                            "description" => "Exact name of the product to remove"
                        ]
                    ],
                    "required" => ["product_name"]
                ]
            ]
        ],
        "temperature" => 0.7,
        "max_tokens" => 500
    ];

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'timeout' => 30,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode($data)
    ]);

    if (is_wp_error($response)) {
        error_log('OpenAI API Error: ' . $response->get_error_message());
        return "Error communicating with OpenAI.";
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if (isset($body['choices'][0]['message']['function_call'])) {
        $function_call = json_decode($body['choices'][0]['message']['function_call']['arguments'], true);
        
        if ($body['choices'][0]['message']['function_call']['name'] === 'add_to_order') {
            $current_order[$function_call['product_name']] = [
                'quantity' => $function_call['quantity'],
                'added_at' => time()
            ];
            set_transient('current_order_items', $current_order, 3600);
            
            // Return confirmation message
            return "Added {$function_call['quantity']} x {$function_call['product_name']} to your order.";
        }
        
        if ($body['choices'][0]['message']['function_call']['name'] === 'remove_from_order') {
            if (isset($current_order[$function_call['product_name']])) {
                unset($current_order[$function_call['product_name']]);
                set_transient('current_order_items', $current_order, 3600);
                return "Removed {$function_call['product_name']} from your order.";
            }
            return "Item not found in your order.";
        }
    }

    return $body['choices'][0]['message']['content'] ?? "Error: No valid response from GPT.";
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

// Add AJAX handler for fetching WooCommerce products
add_action('wp_ajax_fetch_woocommerce_products', 'handle_fetch_woocommerce_products');
add_action('wp_ajax_nopriv_fetch_woocommerce_products', 'handle_fetch_woocommerce_products');

function handle_fetch_woocommerce_products() {
    if (!isset($_POST['order_type'])) {
        wp_send_json_error(['message' => 'Order type is required']);
        return;
    }

    $order_type = sanitize_text_field($_POST['order_type']);

    // Initialize WooCommerce API
    $products = wc_get_products([
        'limit' => 100,
        'status' => 'publish'
    ]);

    $filtered_products = ['products' => []];

    foreach ($products as $product) {
        $product_tags = wp_get_post_terms($product->get_id(), 'product_tag', ['fields' => 'names']);
        $product_categories = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']);

        // Case-insensitive tag matching
        $has_matching_tag = false;
        foreach ($product_tags as $tag) {
            if (strtolower($tag) === strtolower($order_type)) {
                $has_matching_tag = true;
                break;
            }
        }

        if ($has_matching_tag) {
            $filtered_products['products'][] = [
                'name' => $product->get_name(),
                'price' => '$' . $product->get_price(),
                'categories' => $product_categories,
                'tags' => $product_tags
            ];
        }
    }

    // Store the filtered products in transient for GPT context
    set_transient('woocommerce_fetched_products', $filtered_products, 3600);

    wp_send_json_success($filtered_products);
}

// Add new AJAX handler for initialization
add_action('wp_ajax_initialize_gpt_context', 'handle_gpt_context_initialization');
add_action('wp_ajax_nopriv_initialize_gpt_context', 'handle_gpt_context_initialization');

function handle_gpt_context_initialization() {
    if (!isset($_POST['products']) || !isset($_POST['order_type'])) {
        wp_send_json_error(['message' => 'Missing required data']);
        return;
    }

    $products = json_decode(stripslashes($_POST['products']), true);
    $order_type = sanitize_text_field($_POST['order_type']);
    
    // Initialize empty order
    set_transient('current_order_items', [], 3600);
    
    // System message
    $system_message = [
        'role' => 'system',
        'content' => "You are a Real Estate Marketing Assistant. 

Your primary functions:
1. Help customers choose appropriate marketing materials
2. Provide clear product recommendations based on needs
3. Answer questions about product specifications and pricing
4. Track order items as customers request them

When discussing products:
- Be concise but informative
- Group by category (Print Marketing, Photography, Video) when relevant
- Use exact product names and prices
- Focus on key features and benefits

Available products: " . json_encode($products)
    ];
    
    set_transient('gpt_system_message', $system_message, 3600);
    
    wp_send_json_success(['message' => 'Ready']);
}

function get_current_order_summary() {
    $current_order = get_transient('current_order_items') ?: [];
    if (empty($current_order)) {
        return "No items in your order yet.";
    }
    
    $summary = "Current Order:\n";
    foreach ($current_order as $product_name => $details) {
        $summary .= "- {$product_name} (Qty: {$details['quantity']})\n";
    }
    return $summary;
}
