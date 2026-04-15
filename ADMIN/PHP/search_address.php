<?php
// search_address.php

// 1. Allow your Javascript to read this
header('Content-Type: application/json');

// 2. Get the search query
$query = isset($_GET['q']) ? $_GET['q'] : '';

if (strlen($query) < 3) {
    echo json_encode([]);
    exit;
}

// 3. ADVANCED (Prioritizes PH area but allows world)
// The viewbox coordinates cover the Philippines roughly
// NEW (Global Search)
$url = "https://nominatim.openstreetmap.org/search?format=json&q=" . urlencode($query) . "&addressdetails=1&limit=5";

// 4. Create request context with a User-Agent
// IMPORTANT: Nominatim BLOCKS requests without a User-Agent header
$options = [
    "http" => [
        "method" => "GET",
        "header" => "User-Agent: AMVHotelBookingSystem/1.0 (admin@amvhotel.com)\r\n"
    ]
];
$context = stream_context_create($options);

// 5. Fetch the data and pass it back to your Javascript
$response = file_get_contents($url, false, $context);

if ($response === FALSE) {
    // Handle error (e.g. no internet on server)
    echo json_encode([]);
} else {
    echo $response;
}
?>