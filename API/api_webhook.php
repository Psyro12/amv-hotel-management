<?php
//test laang 
// 🟢 1. SETUP
// Webhooks don't need CORS because they are server-to-server
header("Content-Type: application/json");
include 'connection.php';

// 🟢 2. READ THE RAW BODY (Input from PayMongo)
$input = @file_get_contents("php://input");
$event = json_decode($input, true);

// 🟢 3. LOGGING (Crucial for Debugging)
// Since you can't see "echo" messages from a webhook, we save them to a text file.
// Create a file named 'webhook_log.txt' in your folder if it doesn't create automatically.
$logFile = fopen("webhook_log.txt", "a");
$logTime = date("Y-m-d H:i:s");

if (!$event) {
    fwrite($logFile, "[$logTime] Error: No data received\n");
    fclose($logFile);
    http_response_code(400); // Bad Request
    exit();
}

// Log the event type received
$eventType = $event['data']['attributes']['type'] ?? 'unknown';
fwrite($logFile, "[$logTime] Event Received: $eventType\n");

// 🟢 4. HANDLE "PAYMENT.PAID" EVENT
// This is the event PayMongo sends when money successfully enters your account.
if ($eventType == 'payment.paid') {
    
    // Extract details from the payload
    $paymentAttributes = $event['data']['attributes']['data']['attributes'];
    $description = $paymentAttributes['description']; // We saved this as "Order #123 - Room..."
    $amount = $paymentAttributes['amount'] / 100; // Convert back from centavos to Pesos
    
    fwrite($logFile, "[$logTime] Description: $description | Amount: $amount\n");

    // 🟢 5. EXTRACT ORDER ID
    // We parse "Order #123" to get just "123"
    if (preg_match('/Order #(\d+)/', $description, $matches)) {
        $orderId = $matches[1];
        
        // 🟢 6. UPDATE DATABASE
        $sql = "UPDATE orders SET status = 'PAID' WHERE id = '$orderId'";
        
        if ($conn->query($sql) === TRUE) {
            fwrite($logFile, "[$logTime] SUCCESS: Order #$orderId marked as PAID.\n");
            http_response_code(200); // Tell PayMongo "We got it!"
        } else {
            fwrite($logFile, "[$logTime] DB ERROR: " . $conn->error . "\n");
            http_response_code(500);
        }
    } else {
        fwrite($logFile, "[$logTime] Error: Could not find Order ID in description.\n");
    }

} else {
    // If it's just a 'payment.created' or 'source.chargeable' event, we ignore it for now
    fwrite($logFile, "[$logTime] Ignoring event type.\n");
    http_response_code(200);
}

fclose($logFile);
$conn->close();
?>