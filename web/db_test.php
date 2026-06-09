<?php
define('WP_USE_THEMES', false);
require_once('wp/wp-load.php');
global $wpdb;
$columns = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}dfn_events");
print_r($columns);
unlink(__FILE__);
