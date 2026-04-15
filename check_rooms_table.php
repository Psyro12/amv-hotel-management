<?php
require 'ADMIN/PHP/db_connect.php';
$res = $conn->query("DESCRIBE rooms");
if (!$res) {
    die("Error: " . $conn->error);
}
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . " - " . $row['Type'] . "\n";
}
?>