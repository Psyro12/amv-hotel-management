<?php
// guest_reschedule.php
header('Content-Type: application/json');
require 'db_connect.php';

// 1. INPUT VALIDATION
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Request Method']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

$ref         = $input['booking_reference'] ?? '';
$newCheckIn  = $input['new_check_in'] ?? '';
$newCheckOut = $input['new_check_out'] ?? '';
$newRoomId   = $input['new_room_id'] ?? null; // Optional: Only present if user selected a new room

if (empty($ref) || empty($newCheckIn) || empty($newCheckOut)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields.']);
    exit();
}

// 2. FETCH CURRENT BOOKING DETAILS
$sql = "SELECT b.id, b.check_in, b.created_at, b.status, b.arrival_status, b.total_price, br.room_id, r.price as price_per_night, r.name as room_name
        FROM bookings b
        JOIN booking_rooms br ON b.id = br.booking_id
        JOIN rooms r ON br.room_id = r.id
        WHERE b.booking_reference = ? LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $ref);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    echo json_encode(['status' => 'error', 'message' => 'Booking not found.']);
    exit();
}

// 🟢 NEW: PREVENT RESCHEDULING IF GUEST IS ALREADY IN-HOUSE
if ($booking['arrival_status'] === 'in_house') {
    echo json_encode(['status' => 'error', 'message' => 'Rescheduling Denied: Guest is already checked in.']);
    exit();
}

// 3. ENFORCE 3-DAY GRACE PERIOD (Skip this check if we are just finalizing the room swap)
if (!$newRoomId) {
    $createdDateStr = !empty($booking['created_at']) ? $booking['created_at'] : $booking['check_in'];
    $createdDate = new DateTime($createdDateStr);
    $now = new DateTime();
    $hoursPassed = ($createdDate->diff($now)->days * 24) + $createdDate->diff($now)->h;

    if ($hoursPassed > 72) {
        echo json_encode(['status' => 'error', 'message' => 'Rescheduling Denied: The 3-day grace period has expired.']);
        exit();
    }
}

// 4. DETERMINE TARGET ROOM (Current vs New)
$targetRoomId = $newRoomId ? $newRoomId : $booking['room_id'];

// If swapping rooms, get the NEW price
$pricePerNight = $booking['price_per_night'];
$newRoomName = $booking['room_name'];

if ($newRoomId) {
    $stmtRoom = $conn->prepare("SELECT name, price FROM rooms WHERE id = ?");
    $stmtRoom->bind_param("i", $newRoomId);
    $stmtRoom->execute();
    $roomData = $stmtRoom->get_result()->fetch_assoc();
    $pricePerNight = $roomData['price'];
    $newRoomName = $roomData['name'];
}

// 5. CHECK AVAILABILITY
$checkSql = "SELECT b.id, b.check_out FROM bookings b
             JOIN booking_rooms br ON b.id = br.booking_id
             WHERE br.room_id = ? 
             AND b.status IN ('confirmed', 'pending') 
             AND b.arrival_status NOT IN ('checked_out', 'no_show', 'cancelled')
             AND b.id != ? 
             AND (b.check_in < ? AND b.check_out > ?) -- Overlap
             LIMIT 1";

$stmtCheck = $conn->prepare($checkSql);
$stmtCheck->bind_param("iiss", $targetRoomId, $booking['id'], $newCheckOut, $newCheckIn);
$stmtCheck->execute();
$conflictRes = $stmtCheck->get_result();
$isCurrentRoomConflict = ($conflictRes->num_rows > 0);

// --- NEW FLOW: ALWAYS SHOW ROOM SELECTION FIRST ---
// If the user hasn't explicitly selected a room yet, we don't update.
// We just return the availability status and the list of rooms.
if (!$newRoomId) {
    // Find ALL available rooms (including current if it's not in conflict)
    // 🟢 REMOVED LIMIT to show all rooms (like Room 211)
    $altSql = "SELECT r.id, r.name, r.price, r.image_path, r.type, r.capacity, r.bed_type, r.size 
               FROM rooms r
               WHERE r.is_active = 1
               AND r.id NOT IN (
                   SELECT br.room_id FROM booking_rooms br
                   JOIN bookings b ON br.booking_id = b.id
                   WHERE b.status IN ('confirmed', 'pending')
                   AND b.arrival_status NOT IN ('checked_out', 'no_show', 'cancelled')
                   AND b.id != ?
                   AND (b.check_in < ? AND b.check_out > ?)
               ) 
               ORDER BY r.name ASC";
               
    $stmtAlt = $conn->prepare($altSql);
    $stmtAlt->bind_param("iss", $booking['id'], $newCheckOut, $newCheckIn);
    $stmtAlt->execute();
    $altRes = $stmtAlt->get_result();
    
    $alternatives = [];
    while($row = $altRes->fetch_assoc()) {
        $alternatives[] = $row;
    }

    echo json_encode([
        'status' => 'selection_required',
        'is_conflict' => $isCurrentRoomConflict,
        'message' => $isCurrentRoomConflict 
            ? "Room '{$booking['room_name']}' is occupied. Please select another." 
            : "Room '{$booking['room_name']}' is available, but you can also choose others.",
        'next_date' => $isCurrentRoomConflict ? $conflictRes->fetch_assoc()['check_out'] : null,
        'alternatives' => $alternatives,
        'current_room_id' => $booking['room_id']
    ]);
    exit();
}

// 6. UPDATE DATABASE (Only runs if $newRoomId is present)
$d1 = new DateTime($newCheckIn);
$d2 = new DateTime($newCheckOut);
$nights = $d1->diff($d2)->days;
if ($nights < 1) $nights = 1;

$newTotal = $nights * $pricePerNight;

// Update Bookings
$updateBooking = $conn->prepare("UPDATE bookings SET check_in=?, check_out=?, total_price=? WHERE id=?");
$updateBooking->bind_param("ssdi", $newCheckIn, $newCheckOut, $newTotal, $booking['id']);
$updateBooking->execute();

// Update Room ID if changed
if ($newRoomId) {
    $updateRoom = $conn->prepare("UPDATE booking_rooms SET room_id=?, room_name=? WHERE booking_id=? AND room_id=?");
    $updateRoom->bind_param("isii", $newRoomId, $newRoomName, $booking['id'], $booking['room_id']);
    $updateRoom->execute();
}

echo json_encode([
    'status' => 'success', 
    'message' => 'Reschedule Successful!',
    'new_total' => $newTotal
]);
?>