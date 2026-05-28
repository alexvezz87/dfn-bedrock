<?php
/**
 * Plugin Name: Bedrock Compatibility Fixes
 * Description: Declares missing core emoji functions to prevent Fatal Errors on PHP 8.3.
 */

if (!function_exists('print_emoji_detection_script')) {
    function print_emoji_detection_script() {
        // Dummy function to prevent PHP 8.3 fatal errors when plugins trigger this hook
    }
}

if (!function_exists('print_emoji_styles')) {
    function print_emoji_styles() {
        // Dummy function to prevent PHP 8.3 fatal errors when plugins trigger this hook
    }
}
