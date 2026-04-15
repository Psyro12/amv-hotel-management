<?php
// ADMIN FRONT CONTROLLER
// Route all requests through this file to hide actual PHP paths

// 🔐 SECRET ACCESS KEY FOR LOGIN
// Change this to something you prefer
define('ADMIN_ACCESS_KEY', 'AMV-SECRET-772');

$page = isset($_GET['page']) ? basename($_GET['page'], '.php') : 'login';

// Security check - prevent direct access to PHP files
if (php_sapi_name() === 'cli') {
    include 'PHP/login.php';
    exit;
}

// 🛡️ SECRET LOGIN ACCESS CHECK
// Only applies when trying to access the login page
if ($page === 'login') {
    $access_key = $_GET['access'] ?? '';
    if ($access_key !== ADMIN_ACCESS_KEY) {
        // Return 404 to look like the page doesn't exist
        header("HTTP/1.1 404 Not Found");
        include 'PHP/loading.php'; // Or a custom 404 page
        echo "<div style='text-align:center; padding:50px; font-family:sans-serif;'>
                <h1>404 Not Found</h1>
                <p>The requested resource was not found on this server.</p>
              </div>";
        exit;
    }
}

switch ($page) {
    case 'login':
        include 'PHP/login.php';
        break;
    case 'dashboard':
        include 'PHP/dashboard.php';
        break;
    case 'logout':
        include 'PHP/logout.php';
        break;
    case 'loading':
        include 'PHP/loading.php';
        break;
    case 'auth':
        include 'PHP/auth.php';
        break;
    case 'check_session':
        include 'PHP/check_session.php';
        break;
    case 'forgot_password':
        include 'PHP/forgot_password.php';
        break;
    case 'reset_password':
        include 'PHP/reset_password.php';
        break;
    case 'auth_reset':
        include 'PHP/auth_reset.php';
        break;
    case 'guest_reschedule':
        include 'PHP/guest_reschedule.php';
        break;
    case 'update_arrival':
        include 'PHP/update_arrival.php';
        break;
    case 'confirm_qr':
        include 'PHP/confirm_qr.php';
        break;
    case 'send_guest_email':
        include 'PHP/send_guest_email.php';
        break;
    case 'fetch_booking_table':
        include 'PHP/fetch_booking_table.php';
        break;
    case 'get_available_rooms':
        include 'PHP/get_available_rooms.php';
        break;
    case 'get_calendar_data':
        include 'PHP/get_calendar_data.php';
        break;
    case 'manage_rooms':
        include 'PHP/manage_rooms.php';
        break;
    case 'send_reminders':
        include 'PHP/send_reminders.php';
        break;
    case 'save_booking':
        include 'PHP/save_booking.php';
        break;
    case 'approve_booking':
        include 'PHP/approve_booking.php';
        break;
    case 'get_dashboard_stats':
        include 'PHP/get_dashboard_stats.php';
        break;
    case 'verify_payment':
        include 'PHP/verify_payment.php';
        break;
    case 'manage_amenities':
        include 'PHP/manage_amenities.php';
        break;
    case 'get_admin_details':
        include 'PHP/get_admin_details.php';
        break;
    case 'update_admin_profile':
        include 'PHP/update_admin_profile.php';
        break;
    case 'get_pending_orders':
        include 'PHP/get_pending_orders.php';
        break;
    case 'get_pending_bookings':
        include 'PHP/get_pending_bookings.php';
        break;
    case 'update_order':
        include 'PHP/update_order.php';
        break;
    case 'get_news':
        include 'PHP/get_news.php';
        break;
    case 'approve_order':
        include 'PHP/approve_order.php';
        break;
    case 'update_terms':
        include 'PHP/update_terms.php';
        break;
    case 'update_privacy':
        include 'PHP/update_privacy.php';
        break;
    case 'manage_news':
        include 'PHP/manage_news.php';
        break;
    case 'fetch_food_table':
        include 'PHP/fetch_food_table.php';
        break;
    case 'manage_events':
        include 'PHP/manage_events.php';
        break;
    case 'manage_food':
        include 'PHP/manage_food.php';
        break;
    case 'get_transactions':
        include 'PHP/get_transactions.php';
        break;
    case 'get_payment_settings':
        include 'PHP/get_payment_settings.php';
        break;
    case 'update_payment_settings':
        include 'PHP/update_payment_settings.php';
        break;
    case 'get_all_guests':
        include 'PHP/get_all_guests.php';
        break;
    case 'get_guest_details':
        include 'PHP/get_guest_details.php';
        break;
    case 'update_guest_email':
        include 'PHP/update_guest_email.php';
        break;
    case 'update_guest_profile':
        include 'PHP/update_guest_profile.php';
        break;
    case 'settings_action':
        include 'PHP/settings_action.php';
        break;
    default:
        include 'PHP/login.php';
        break;
}