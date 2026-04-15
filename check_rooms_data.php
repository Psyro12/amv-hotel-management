<?php
require 'ADMIN/PHP/db_connect.php';
$res = $conn->query("SELECT id, name, is_active FROM rooms");
if (!$res) {
    die("Error: " . $conn->error);
}
while($row = $res->fetch_assoc()) {
    echo $row['id'] . " - " . $row['name'] . " - " . $row['is_active'] . "\n";
}
?>