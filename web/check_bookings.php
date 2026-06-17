<?php
define('WP_USE_THEMES', false);
require_once('wp/wp-load.php');
global $wpdb;

echo "=== LAST 10 BOOKINGS ===\n";
$bookings = $wpdb->get_results("SELECT id, order_id, event_id, total_persons, persons_standard, persons_fai, status FROM {$wpdb->prefix}dfn_bookings ORDER BY id DESC LIMIT 10", ARRAY_A);
foreach ($bookings as $b) {
    echo "ID: {$b['id']}, Order ID: {$b['order_id']}, Event ID: {$b['event_id']}, Total: {$b['total_persons']}, Std: {$b['persons_standard']}, FAI: {$b['persons_fai']}, Status: {$b['status']}\n";
}

echo "\n=== LAST 10 BOOKING SLOTS ===\n";
$bslots = $wpdb->get_results("SELECT booking_id, slot_id, persons FROM {$wpdb->prefix}dfn_booking_slots ORDER BY booking_id DESC LIMIT 10", ARRAY_A);
foreach ($bslots as $bs) {
    echo "Booking ID: {$bs['booking_id']}, Slot ID: {$bs['slot_id']}, Persons: {$bs['persons']}\n";
}

echo "\n=== SLOTS BOOKED COUNT ===\n";
$slots = $wpdb->get_results("SELECT id, slot_date, slot_time_start, booked_count, capacity FROM {$wpdb->prefix}dfn_event_slots WHERE booked_count > 0 ORDER BY slot_date DESC, slot_time_start DESC LIMIT 10", ARRAY_A);
foreach ($slots as $s) {
    echo "Slot ID: {$s['id']}, Date: {$s['slot_date']} {$s['slot_time_start']}, Booked Count: {$s['booked_count']}/{$s['capacity']}\n";
}
