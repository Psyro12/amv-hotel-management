<?php
session_start();
header('Content-Type: application/json'); // Return JSON for the frontend to handle success/error
require '../DB-CONNECTIONS/db_connect_2.php'; // Ensure correct path

try {
    // 1. Capture POST Data (From your Confirmation Page)
    // Assuming you send these via AJAX or Form POST
    $checkIn = $_POST['checkin'];
    $checkOut = $_POST['checkout'];
    $guestData = [
        'salutation' => $_POST['salutation'],
        'firstname'  => $_POST['firstname'],
        'lastname'   => $_POST['lastname'],
        'email'      => $_POST['email'],
        'phone'      => $_POST['contact'], // Ensure field names match your form
        'nationality'=> $_POST['nationality'],
        'gender'     => $_POST['gender'],
        'birthdate'  => $_POST['birthdate'],
        'address'    => $_POST['address'],
        'arrival_time'=> $_POST['arrival_time'],
        'adults'     => $_POST['adults'],
        'children'   => $_POST['children'],
        'payment_method' => $_POST['payment_method']
    ];
    
    // Decode the JSON string of rooms sent from the previous step
    $selectedRooms = json_decode($_POST['selected_rooms'], true); 

    if (empty($selectedRooms)) {
        throw new Exception("No rooms selected.");
    }

    // 2. Start Transaction (The Safety Net)
    $conn->begin_transaction();

    // ---------------------------------------------------------
    // 🚨 DOUBLE BOOKING PREVENTION (The Gatekeeper) 🚨
    // ---------------------------------------------------------
    
    // Prepare the checker query
    // We check if any of the requested rooms are already taken
    $sql_check = "SELECT b.id, br.room_name 
                  FROM bookings b 
                  JOIN booking_rooms br ON b.id = br.booking_id 
                  WHERE br.room_id = ? 
                  AND b.status IN ('confirmed', 'pending') 
                  AND b.arrival_status != 'checked_out' -- Important: Ignore checked-out rooms
                  AND b.check_in < ? 
                  AND b.check_out > ? 
                  LIMIT 1 FOR UPDATE"; // Lock the row

    $stmtCheck = $conn->prepare($sql_check);

    foreach ($selectedRooms as $room) {
        $roomId = $room['id']; // Make sure your JS sends 'id'
        
        // Bind: i (integer), s (string), s (string)
        $stmtCheck->bind_param("iss", $roomId, $checkOut, $checkIn);
        $stmtCheck->execute();
        $stmtCheck->store_result();

        if ($stmtCheck->num_rows > 0) {
            // CONFLICT DETECTED!
            $stmtCheck->close();
            // Rollback immediately so nothing is saved
            $conn->rollback();
            
            // Tell the user exactly what happened
            throw new Exception("We are sorry! Room " . $room['name'] . " was just booked by another guest a moment ago.");
        }
    }
    $stmtCheck->close();
    // ---------------------------------------------------------


    // 3. Insert Booking Record
    $ref = 'AMV-' . strtoupper(substr(md5(uniqid()), 0, 6));
    $status = 'pending'; // Guests usually start as pending until paid
    $source = 'online';
    $arrival = 'awaiting_arrival';
    $totalPrice = $_POST['total_price'];

    $stmt = $conn->prepare("INSERT INTO bookings (booking_reference, check_in, check_out, total_price, status, booking_source, arrival_status, payment_method) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssdssss", $ref, $checkIn, $checkOut, $totalPrice, $status, $source, $arrival, $guestData['payment_method']);
    
    if (!$stmt->execute()) throw new Exception("Booking Error: " . $stmt->error);
    $bookingId = $conn->insert_id;
    $stmt->close();

    // 4. Insert Guest Details
    $stmtGuest = $conn->prepare("INSERT INTO booking_guests (booking_id, salutation, first_name, last_name, email, phone, nationality, gender, birthdate, address, arrival_time, adults_count, children_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmtGuest->bind_param("issssssssssii", $bookingId, $guestData['salutation'], $guestData['firstname'], $guestData['lastname'], $guestData['email'], $guestData['phone'], $guestData['nationality'], $guestData['gender'], $guestData['birthdate'], $guestData['address'], $guestData['arrival_time'], $guestData['adults'], $guestData['children']);
    
    if (!$stmtGuest->execute()) throw new Exception("Guest Error: " . $stmtGuest->error);
    $stmtGuest->close();

    // 5. Insert Booking Rooms
    $stmtRoom = $conn->prepare("INSERT INTO booking_rooms (booking_id, room_id, room_name, price_per_night) VALUES (?, ?, ?, ?)");
    
    foreach ($selectedRooms as $room) {
        $price = $room['price']; // Ensure this is sent from frontend
        $stmtRoom->bind_param("iisd", $bookingId, $room['id'], $room['name'], $price);
        if (!$stmtRoom->execute()) throw new Exception("Room Error: " . $stmtRoom->error);
    }
    $stmtRoom->close();

    // 6. Success! Commit the changes.
    $conn->commit();
    echo json_encode(['status' => 'success', 'ref' => $ref]);

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>