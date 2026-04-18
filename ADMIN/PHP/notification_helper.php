<?php
// FILE: ADMIN/PHP/notification_helper.php

/**
 * Sends a notification to the Guest App and logs it in the database.
 */
function sendAppNotification($conn, $email, $source, $title, $message, $type) {
    if (empty($email)) return;

    // 1. Log to Database for History
    $stmt = $conn->prepare("INSERT INTO guest_notifications (email, account_source, title, message, type, is_read, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())");
    $stmt->bind_param("sssss", $email, $source, $title, $message, $type);
    $stmt->execute();
    $stmt->close();

    // 2. Fetch FCM Token from Users table
    $fcm_token = null;
    $stmt_token = $conn->prepare("SELECT fcm_token FROM users WHERE email = ? LIMIT 1");
    $stmt_token->bind_param("s", $email);
    $stmt_token->execute();
    $res_token = $stmt_token->get_result();
    if ($row = $res_token->fetch_assoc()) {
        $fcm_token = $row['fcm_token'];
    }
    $stmt_token->close();

    // 3. If token exists, send FCM Push Notification (HTTP v1)
    if (!empty($fcm_token)) {
        sendFCM_v1($fcm_token, $title, $message, $type);
    }
}

/**
 * Sends notification using Firebase Cloud Messaging HTTP v1 API.
 */
function sendFCM_v1($token, $title, $body, $type) {
    $serviceAccountPath = __DIR__ . '/service-account.json';
    if (!file_exists($serviceAccountPath)) {
        error_log("FCM Error: service-account.json not found in " . __DIR__);
        return false;
    }

    $serviceAccount = json_decode(file_get_contents($serviceAccountPath), true);
    $projectId = $serviceAccount['project_id'];
    $accessToken = getGoogleAccessToken($serviceAccount);

    if (!$accessToken) {
        error_log("FCM Error: Failed to generate Google Access Token.");
        return false;
    }

    $url = "https://fcm.googleapis.com/v1/projects/$projectId/messages:send";

    // HTTP v1 Payload Structure
    $payload = [
        'message' => [
            'token' => $token,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'data' => [
                'type' => $type,
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            ],
            'android' => [
                'priority' => 'high',
                'notification' => [
                    'sound' => 'default',
                    'icon' => 'ic_notification',
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                ],
            ],
        ],
    ];

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("FCM v1 API Error ($httpCode): " . $result);
    }

    return $result;
}

/**
 * Generates an OAuth2 Access Token for Firebase using the Service Account JSON.
 * Pure PHP implementation to avoid external dependencies.
 */
function getGoogleAccessToken($sa) {
    $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
    
    $now = time();
    $payload = json_encode([
        'iss' => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'exp' => $now + 3600,
        'iat' => $now,
    ]);

    $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));

    $signature = '';
    openssl_sign($base64UrlHeader . "." . $base64UrlPayload, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256);
    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

    $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
    ]));

    $result = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($result, true);
    return $data['access_token'] ?? null;
}
?>
