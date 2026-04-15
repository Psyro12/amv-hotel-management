<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AMV Hotel Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../STYLE/login_style.css">
    <!-- <link rel="stylesheet" href="../STYLE/design_styles.css"> -->
    <link rel="stylesheet" href="../STYLE/utilities.css">
</head>

<body>

    <a href="signin_page.php" class="back-link">
        <i class="fa-solid fa-arrow-left"></i> Back
    </a>

    <div class="logo-container">
        <img src="../../IMG/5.png" alt="AMV Logo">
        <div class="logo-text">
            <span>AMV</span>
            <span>Hotel</span>
        </div>
    </div>
    <div class="login-container g-4">
        <a id="googleBtn" class="login-btn py-3">
            <img src="https://www.svgrepo.com/show/475656/google-color.svg" alt="Google">
            Continue with email
        </a>

        <div class="divider">
            <hr>
            <span>or</span>
            <hr>
        </div>

        <a id="fbBtn" class="login-btn py-3">
            <img src="https://www.svgrepo.com/show/475647/facebook-color.svg" alt="Facebook">
            Continue with facebook
        </a>
    </div>

</body>
<script type="module" src="../SCRIPT/google_login.js"></script>
<script type="module" src="../SCRIPT/facebook_login.js"></script>