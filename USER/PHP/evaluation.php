<?php
// FILE: evaluation.php (Main Folder)
require 'db_connect.php'; 

$ref = $_GET['ref'] ?? '';
$message = "";
$isValidRef = false;
$guestName = "";

// 1. Verify Booking Reference
if ($ref) {
    $stmt = $conn->prepare("SELECT bg.first_name FROM bookings b JOIN booking_guests bg ON b.id = bg.booking_id WHERE b.booking_reference = ?");
    $stmt->bind_param("s", $ref);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $isValidRef = true;
        $guestName = $result->fetch_assoc()['first_name'];
    }
}

// 2. Handle Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isValidRef) {
    $ref = $_POST['ref'];
    $comment = $_POST['comment'];
    
    // Capture individual ratings
    $r_overall     = $_POST['rating_overall'];
    $r_room        = $_POST['rating_room'];
    $r_service     = $_POST['rating_service'];
    $r_cleanliness = $_POST['rating_cleanliness'];
    $r_amenities   = $_POST['rating_amenities'];

    $sql = "INSERT INTO guest_feedback 
            (booking_reference, rating_overall, rating_room, rating_service, rating_cleanliness, rating_amenities, comments) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("siiiiis", $ref, $r_overall, $r_room, $r_service, $r_cleanliness, $r_amenities, $comment);
    
    if ($stmt->execute()) {
        $message = "Thank you! Your feedback helps us improve.";
    } else {
        $message = "Error saving feedback.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hotel Evaluation - AMV</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Montserrat', sans-serif; background: #f4f4f4; display:flex; justify-content:center; align-items:center; min-height:100vh; margin:0; padding: 20px; }
        
        .card { 
            background: white; 
            padding: 40px; 
            border-radius: 12px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.1); 
            width: 100%; 
            max-width: 500px; /* Made slightly wider for stars */
            text-align: center; 
        }

        h2 { color: #333; margin-top: 0; margin-bottom: 5px; }
        .subtitle { color: #666; font-size: 0.9rem; margin-bottom: 25px; }

        /* --- STAR RATING CSS --- */
        .rating-group {
            margin-bottom: 20px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
            padding-bottom: 15px;
        }

        .rating-label {
            display: block;
            font-weight: 700;
            font-size: 0.95rem;
            color: #444;
            margin-bottom: 8px;
        }

        .stars {
            display: inline-flex;
            flex-direction: row-reverse; /* Magic trick: Allows selecting 1-5 easily */
            justify-content: flex-end;
        }

        .stars input { display: none; } /* Hide radio buttons */

        .stars label {
            font-size: 2rem;
            color: #ddd;
            cursor: pointer;
            transition: color 0.2s;
            margin-right: 5px;
        }

        /* The Star Character */
        .stars label:before { content: '★'; }

        /* Highlight stars on hover and checked */
        .stars input:checked ~ label,
        .stars label:hover,
        .stars label:hover ~ label {
            color: #FFA000; /* Gold Color */
        }

        .rating-desc {
            font-size: 0.75rem;
            color: #999;
            margin-left: 10px;
            font-weight: 500;
        }

        /* --- FORM ELEMENTS --- */
        textarea { 
            width: 100%; 
            padding: 12px; 
            margin: 10px 0 20px 0; 
            border: 1px solid #ddd; 
            border-radius: 8px; 
            box-sizing: border-box; 
            font-family: inherit; 
            resize: vertical;
        }

        .btn { 
            background: #B88E2F; 
            color: white; 
            border: none; 
            padding: 15px 20px; 
            border-radius: 8px; 
            cursor: pointer; 
            width: 100%; 
            font-weight: bold; 
            font-size: 1rem; 
            transition: background 0.3s;
        }
        .btn:hover { background: #967d26; }

        .success-msg { color: #10B981; }
        .error-msg { color: #EF4444; }

    </style>
</head>
<body>

    <div class="card">
        <?php if($message): ?>
            <div style="font-size: 3rem; color: #10B981; margin-bottom: 10px;">✓</div>
            <h3 class="success-msg"><?php echo $message; ?></h3>
            <p>We appreciate your time!</p>

        <?php elseif (!$isValidRef): ?>
            <div style="font-size: 3rem; color: #EF4444; margin-bottom: 10px;">⚠</div>
            <h3 class="error-msg">Invalid Link</h3>
            <p>This booking reference could not be found.</p>

        <?php else: ?>
            <h2>Hello, <?php echo htmlspecialchars($guestName); ?>!</h2>
            <p class="subtitle">Please rate your experience with us.</p>
            
            <p style="font-size: 0.75rem; color: #888; margin-bottom: 20px;">
                (1 star = Poor, 5 stars = Excellent)
            </p>

            <form method="POST">
                <input type="hidden" name="ref" value="<?php echo htmlspecialchars($ref); ?>">

                <?php 
                function renderStars($name, $label) {
                    echo '<div class="rating-group">';
                    echo '<label class="rating-label">' . $label . '</label>';
                    echo '<div class="stars">';
                    
                    // Stars are reversed in HTML for CSS float logic (5 to 1)
                    for ($i = 5; $i >= 1; $i--) {
                        $id = $name . '-' . $i;
                        echo '<input type="radio" id="' . $id . '" name="' . $name . '" value="' . $i . '" required>';
                        echo '<label for="' . $id . '" title="' . $i . ' Stars"></label>';
                    }
                    
                    echo '</div>';
                    echo '</div>';
                }
                ?>

                <?php renderStars('rating_overall', 'Overall Stay'); ?>

                <?php renderStars('rating_room', 'Room Comfort'); ?>

                <?php renderStars('rating_amenities', 'Amenities'); ?>

                <?php renderStars('rating_service', 'Staff & Service'); ?>

                <?php renderStars('rating_cleanliness', 'Cleanliness'); ?>

                <div style="text-align: left; margin-top: 15px;">
                    <label class="rating-label">Additional Comments</label>
                    <textarea name="comment" rows="4" placeholder="Tell us what you liked or how we can improve..."></textarea>
                </div>

                <button type="submit" class="btn">Submit Feedback</button>
            </form>
        <?php endif; ?>
    </div>

</body>
</html>