<?php
/**
 * Plugin Name: WooCommerce GPT Chatbot
 * Description: A site-wide chatbot modal that integrates with ChatGPT and WooCommerce API.
 * Version: 1.0.7
 * Author: Bret Phillips
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Include GPT Request File
require_once plugin_dir_path(__FILE__) . 'assets/gpt-comms.php';

// Enqueue external CSS and JS files
function enqueue_chatbot_assets() {
    wp_enqueue_style('chatbot-style', plugin_dir_url(__FILE__) . 'assets/style.css');

    // Load chatbot UI first
    wp_enqueue_script('chatbot-ui', plugin_dir_url(__FILE__) . 'assets/chatbot-ui.js', array('jquery'), null, true);
    
}
add_action('wp_enqueue_scripts', 'enqueue_chatbot_assets');
?>
