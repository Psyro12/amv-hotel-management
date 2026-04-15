<?php
require 'ADMIN/PHP/db_connect.php';
$res = $conn->query("DESCRIBE booking_rooms");
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . " - " . $row['Type'] . "\n";
}
?>