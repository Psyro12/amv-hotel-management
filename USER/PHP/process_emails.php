<?php
// process_emails.php
// This script checks the DB for pending emails and sends them.

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../PHPMailer/src/Exception.php';
require '../PHPMailer/src/PHPMailer.php';
require '../PHPMailer/src/SMTP.php';
require_once '../DB-CONNECTIONS/db_connect_2.php'; 

// Fetch up to 10 pending emails at a time
$sql = "SELECT * FROM amv_db.email_queue WHERE status = 'pending' LIMIT 10";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    $mail = new PHPMailer(true);

    // --- SERVER SETTINGS (Config once) ---
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com'; 
    $mail->SMTPAuth   = true;
    $mail->Username   = 'your_real_email@gmail.com'; // REPLACE THIS
    $mail->Password   = 'your_app_password';         // REPLACE THIS
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->setFrom('no-reply@amvhotel.com', 'AMV Hotel Reservations');
    $mail->isHTML(true);

    while ($row = $result->fetch_assoc()) {
        try {
            // Reset recipients for loop
            $mail->clearAddresses();
            
            $mail->addAddress($row['recipient_email'], $row['recipient_name']);
            $mail->Subject = $row['subject'];
            $mail->Body    = $row['body'];

            $mail->send();

            // Mark as SENT
            $conn->query("UPDATE amv_db.email_queue SET status='sent', sent_at=NOW() WHERE id=" . $row['id']);
            echo "Email sent to: " . $row['recipient_email'] . "<br>";

        } catch (Exception $e) {
            // Mark as FAILED
            $conn->query("UPDATE amv_db.email_queue SET status='failed' WHERE id=" . $row['id']);
            echo "Failed: " . $mail->ErrorInfo . "<br>";
        }
    }
} else {
    echo "No pending emails.";
}
?>