<?php
// ADMIN/PHP/send_reminders.php

require 'db_connect.php';
require_once '../../USER/PHPMailer-master/src/Exception.php';
require_once '../../USER/PHPMailer-master/src/PHPMailer.php';
require_once '../../USER/PHPMailer-master/src/SMTP.php';

session_start();
header('Content-Type: text/plain');

// ⚠️ PRODUCTION URL
$baseURL = "https://amvhotel.online"; 

$today = date('Y-m-d');

// Find guests checking out TODAY who are In-House
$sql = "SELECT b.id, b.booking_reference, bg.email, bg.first_name, bg.last_name
        FROM bookings b
        JOIN booking_guests bg ON b.id = bg.booking_id
        WHERE b.check_out = '$today' 
        AND b.arrival_status = 'in_house'
        AND b.status = 'confirmed'";

$result = $conn->query($sql);
$count = 0;

// 🟢 FETCH HOTEL CONTACT INFO
$hotel_email = "support@amvhotel.online";
$hotel_phone = "+63 901 234 5678";
$hotel_name = "AMV Hotel";
$hotel_address = "Mamburao, Occidental Mindoro, Philippines";

$sql_hotel = "SELECT name, email, contact_number FROM admin_user WHERE ID = 1 LIMIT 1";
$res_hotel = $conn->query($sql_hotel);
if ($res_hotel && $hrow = $res_hotel->fetch_assoc()) {
    $hotel_name = $hrow['name'] ?? $hotel_name;
    $hotel_email = $hrow['email'] ?? $hotel_email;
    $hotel_phone = $hrow['contact_number'] ?? $hotel_phone;
}

if ($result && $result->num_rows > 0) {
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'periolarren@gmail.com';
    $mail->Password = 'ftvp ilfl utmq pdgg';
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    // 🟢 RELAX SSL VERIFICATION
    $mail->SMTPOptions = array(
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        )
    );

    $mail->setFrom('periolarren@gmail.com', "$hotel_name");
    $mail->isHTML(true);

    while ($row = $result->fetch_assoc()) {
        $email = $row['email'];
        $name = $row['first_name']; // Just first name is friendlier
        $ref = $row['booking_reference'];

        // 🟢 GENERATE THE LINK
        // Pointing to the root as per production setup
        $evalLink = $baseURL . "/evaluation.php?ref=" . $ref;

        if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                $mail->clearAddresses();
                $mail->addAddress($email, $name);

                $mail->Subject = "Checkout Reminder & Feedback - $hotel_name";
                
                $mail->Body = "
                <div style='font-family: Montserrat, Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e0e0e0; border-radius: 8px; overflow:hidden;'>
                    
                    <div style='background-color: #B8860B; padding: 25px; text-align: center;'>
                        <h2 style='margin:0; color: white; letter-spacing: 1px;'>Checkout Reminder</h2>
                    </div>

                    <div style='padding: 30px; background-color: #ffffff; color: #333;'>
                        <p style='font-size: 1.1em;'>Hi <strong>$name</strong>,</p>
                        
                        <p>We hope you've had a wonderful stay with us at $hotel_name!</p>
                        
                        <p>This is a gentle reminder that your scheduled checkout is <strong>today at 12:00 PM</strong>. Please visit the front desk to return your key card.</p>
                        
                        <hr style='border: 0; border-top: 1px solid #eee; margin: 25px 0;'>
                        
                        <div style='text-align: center;'>
                            <p style='font-weight: 600; margin-bottom: 15px;'>How was your experience?</p>
                            <p style='font-size: 0.9em; color: #666; margin-bottom: 20px;'>We'd love to hear your thoughts to help us improve.</p>
                            
                            <a href='$evalLink' style='background-color: #333; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold; display: inline-block;'>
                                Rate Your Stay
                            </a>
                        </div>

                        <div style='background-color: #F9FAFB; padding: 15px; border-radius: 6px; border: 1px solid #E5E7EB; margin-top: 30px;'>
                            <h4 style='margin: 0 0 10px 0; color: #111827;'>$hotel_name Contact Information</h4>
                            <p style='margin: 5px 0; font-size: 0.9em;'>📍 <strong>Address:</strong> $hotel_address</p>
                            <p style='margin: 5px 0; font-size: 0.9em;'>📞 <strong>Phone:</strong> $hotel_phone</p>
                            <p style='margin: 5px 0; font-size: 0.9em;'>📧 <strong>Email:</strong> $hotel_email</p>
                        </div>
                        
                        <p style='margin-top: 30px; font-size: 0.85em; color: #888; text-align: center;'>
                            Regards,<br>
                            <strong>$hotel_name Management</strong>
                        </p>
                    </div>
                </div>";

                $mail->send();
                $count++;

            } catch (Exception $e) {
                error_log("Reminder Error ($ref): " . $mail->ErrorInfo);
            }
        }
    }

    if ($count > 0) {
        $notifTitle = "Checkout Reminders Sent";
        $notifDesc = "Emailed $count guest(s) due for checkout today.";
        $stmt_notif = $conn->prepare("INSERT INTO system_notifications (title, description, type, is_read, created_at) VALUES (?, ?, 'reminder', 0, NOW())");
        $stmt_notif->bind_param("ss", $notifTitle, $notifDesc);
        $stmt_notif->execute();
    }

    echo "Sent $count reminder emails.";

} else {
    echo "No pending checkouts found.";
}
?>