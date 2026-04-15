<?php
// ADMIN/PHP/test_reminder.php (TEMPORARY TEST FILE)

require 'db_connect.php';
require_once '../../USER/PHPMailer-master/src/Exception.php';
require_once '../../USER/PHPMailer-master/src/PHPMailer.php';
require_once '../../USER/PHPMailer-master/src/SMTP.php';

header('Content-Type: text/plain');

$baseURL = "https://amvhotel.online"; 

// Get test details from URL parameters
$email = $_GET['email'] ?? 'periolarren@gmail.com';
$name = $_GET['name'] ?? 'Arren Periol';
$ref = $_GET['ref'] ?? 'AMV-560687';

if (empty($email)) {
    die("Usage: test_reminder.php?email=your-email@example.com&name=John&ref=REF123");
}

// 🟢 THE UPDATED LINK WE ARE TESTING
$evalLink = $baseURL . "/evaluation.php?ref=" . $ref;

$mail = new PHPMailer\PHPMailer\PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'periolarren@gmail.com';
    $mail->Password = 'ftvp ilfl utmq pdgg';
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    $mail->SMTPOptions = array(
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        )
    );

    $mail->setFrom('periolarren@gmail.com', 'AMV Hotel (Test)');
    $mail->addAddress($email, $name);
    $mail->isHTML(true);
    $mail->Subject = "TEST: Checkout Reminder Link";

    $mail->Body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e0e0e0; padding: 20px;'>
        <h2 style='color: #B8860B;'>Test Reminder</h2>
        <p>This is a test to verify the link structure for <strong>$name</strong> (Ref: $ref).</p>
        
        <div style='text-align: center; margin: 30px 0;'>
            <a href='$evalLink' style='background-color: #333; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold;'>
                Rate Your Stay
            </a>
        </div>
        
        <p style='font-size: 0.8em; color: #666;'>
            Direct Link: <br>
            <a href='$evalLink'>$evalLink</a>
        </p>
    </div>";

    $mail->send();
    echo "SUCCESS: Test email sent to $email
";
    echo "LINK SENT: $evalLink";

} catch (Exception $e) {
    echo "ERROR: Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
}
?>