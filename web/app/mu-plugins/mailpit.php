<?php
/**
 * Plugin Name: Mailpit SMTP Configuration
 * Description: Redirect all WordPress emails to Mailpit in development.
 * Version: 1.0.0
 * Author: Antigravity
 */

use function Env\env;

// Disable WP Mail SMTP plugin dynamically in development to prevent it from bypassing localhost
add_filter('option_active_plugins', function ($plugins) {
    if (defined('WP_ENV') && WP_ENV === 'development' && is_array($plugins)) {
        $key = array_search('wp-mail-smtp/wp_mail_smtp.php', $plugins);
        if ($key !== false) {
            unset($plugins[$key]);
            $plugins = array_values($plugins); // Re-index array
        }
    }
    return $plugins;
});

add_action('phpmailer_init', function ($phpmailer) {
    // Only intercept emails in development environment
    if (defined('WP_ENV') && WP_ENV === 'development') {
        $phpmailer->isSMTP();
        $phpmailer->Host = env('SMTP_HOST') ?: '127.0.0.1';
        $phpmailer->Port = env('SMTP_PORT') ?: 1025;
        $phpmailer->SMTPAuth = false;
        $phpmailer->SMTPSecure = '';
        $phpmailer->Username = '';
        $phpmailer->Password = '';
    }
}, 999999);
