<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AMV Hotel</title>
    <link rel="icon" type="image/png" href="../../IMG/5.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../STYLE/signin_page.css">
    <link rel="stylesheet" href="../STYLE/utilities.css">

    <style>
        .modal {
            display: none;
            /* Hidden by default */
            position: fixed;
            z-index: 2000;
            /* Above all content */
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.6);
            /* Black w/ opacity */
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 30px;
            border-radius: 10px;
            width: 80%;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            animation: fadeIn 0.3s ease-out;
        }

        .modal-content h2 {
            color: #2C1045;
            margin-bottom: 15px;
        }

        .modal-content p {
            color: #555;
            margin-bottom: 25px;
        }

        .modal-buttons {
            display: flex;
            justify-content: space-around;
            gap: 10px;
        }

        .modal .btn {
            flex: 1;
            padding: 10px 20px;
            font-size: 14px;
        }

        .modal .close-btn {
            background: #ccc;
            color: #333;
        }

        .modal .close-btn:hover {
            background: #aaa;
        }

        /* Animation */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }
    </style>
</head>

<body>
    <header>
        <div class="logo-container">
            <img src="../../IMG/5.png" alt="AMV Logo">
            <div class="logo-text">
                <span>AMV</span>
                <span>Hotel</span>
            </div>
        </div>

        <nav>
            <a href="login.php" class="btn">Sign In</a>
        </nav>
    </header>

    <section class="hero">
        <div class="hero-content">
            <h1>Welcome to AMV Hotel</h1>
            <p>Experience comfort and luxury in the heart of the city. Book your stay with us today!</p>
            <a href="#" class="btn login-required">Book Now</a>
        </div>

        <div class="scroll-indicator">
            <i class="fa-solid fa-chevron-down"></i>
        </div>
    </section>

    <section class="intro">
        <div class="intro-content">
            <div class="intro-text">
                <h2>About AMV Hotel</h2>
                <p>
                    At AMV Hotel, we combine luxury and comfort to provide a memorable stay for every guest.
                    Located in the heart of the city, our hotel offers modern rooms, fine dining, and relaxing
                    amenities to make you feel right at home. Whether you’re traveling for business or leisure,
                    we are here to give you an exceptional experience.
                </p>
                <a href="#" class="btn">Learn More</a>
            </div>
            <div class="intro-image">
                <img src="../../IMG/intro.png" alt="AMV Hotel Overview">
            </div>
        </div>
    </section>

    <section class="features">
        <div class="feature">
            <i class="fa-solid fa-bed"></i>
            <h3>Luxury Rooms</h3>
            <p>Enjoy modern, comfortable, and fully furnished rooms with a breathtaking view.</p>
        </div>
        <div class="feature">
            <i class="fa-solid fa-utensils"></i>
            <h3>Fine Dining</h3>
            <p>Delight in world-class cuisine at our in-house restaurant and bar.</p>
        </div>
        <div class="feature">
            <i class="fa-solid fa-spa"></i>
            <h3>Spa & Wellness</h3>
            <p>Relax and rejuvenate with our premium spa and wellness facilities.</p>
        </div>
        <div class="feature">
            <i class="fa-solid fa-wifi"></i>
            <h3>Free Wi-Fi</h3>
            <p>Stay connected with complimentary high-speed internet throughout the hotel.</p>
        </div>
    </section>


    <section class="foods">
        <h2 class="section-title">Our Specialties</h2>
        <p class="section-subtitle">Delight in our hotel’s signature dishes prepared by top chefs.</p>

        <div class="food-grid">
            <div class="food-item login-required">
                <img src="../../IMG/food_1.jpg" alt="Grilled Salmon">
                <h3>Grilled Salmon</h3>
                <p>Freshly grilled salmon with herbs and lemon butter sauce.</p>
                <span class="price">₱450</span>
            </div>

            <div class="food-item login-required">
                <img src="../../IMG/food_2.jpg" alt="Steak & Fries">
                <h3>Steak & Fries</h3>
                <p>Juicy sirloin steak served with crispy golden fries.</p>
                <span class="price">₱850</span>
            </div>

            <div class="food-item login-required">
                <img src="../../IMG/food_3.jpg" alt="Pasta Primavera">
                <h3>Pasta Primavera</h3>
                <p>Italian-style pasta tossed with fresh vegetables and parmesan.</p>
                <span class="price">₱380</span>
            </div>

            <div class="food-item login-required">
                <img src="../../IMG/food_4.JPG" alt="Cheesecake">
                <h3>Classic Cheesecake</h3>
                <p>Creamy cheesecake with a buttery crust and strawberry topping.</p>
                <span class="price">₱250</span>
            </div>

            <div class="food-item extra login-required">
                <img src="../../IMG/food_5.jpg" alt="Caesar Salad">
                <h3>Caesar Salad</h3>
                <p>Crisp romaine lettuce with parmesan and creamy Caesar dressing.</p>
                <span class="price">₱300</span>
            </div>

            <div class="food-item extra login-required">
                <img src="../../IMG/food_6.jpg" alt="Chicken Adobo">
                <h3>Chicken Adobo</h3>
                <p>Classic Filipino dish simmered in soy sauce, vinegar, and garlic.</p>
                <span class="price">₱280</span>
            </div>
            <div class="food-item extra login-required">
                <img src="../../IMG/food_7.jpg" alt="Burger & Fries">
                <h3>Burger & Fries</h3>
                <p>Juicy beef burger with cheese, lettuce, and crispy fries.</p>
                <span class="price">₱320</span>
            </div>
            <div class="food-item extra login-required">
                <img src="../../IMG/food_8.jpg" alt="Chocolate Lava Cake">
                <h3>Chocolate Lava Cake</h3>
                <p>Warm chocolate cake with a gooey molten center.</p>
                <span class="price">₱280</span>
            </div>
        </div>

        <div class="food-toggle">
            <button id="toggleFoodsBtn" class="btn">Show More</button>
        </div>
    </section>


    <section class="rooms" id="rooms">
        <h2 class="section-title">Our Rooms</h2>
        <p class="section-subtitle">Choose from our selection of comfortable and stylish rooms.</p>

        <div class="room-grid">
            <div class="room-item">
                <img src="../../IMG/room_1.jpg" alt="Deluxe Room">
                <h3>Deluxe Room</h3>
                <p>Spacious room with a queen-size bed, perfect for couples.</p>
                <a href="#" class="btn login-required">Book Now</a>
            </div>

            <div class="room-item">
                <img src="../../IMG/room_2.jpg" alt="Executive Suite">
                <h3>Executive Suite</h3>
                <p>Luxury suite with city views and modern amenities.</p>
                <a href="#" class="btn login-required">Book Now</a>
            </div>

            <div class="room-item">
                <img src="../../IMG/room_3.jpg" alt="Family Room">
                <h3>Family Room</h3>
                <p>Comfortable room designed for families with children.</p>
                <a href="#" class="btn login-required">Book Now</a>
            </div>

            <div class="room-item">
                <img src="../../IMG/room_4.jpg" alt="Single Room">
                <h3>Single Room</h3>
                <p>Cozy room ideal for solo travelers on business or leisure.</p>
                <a href="#" class="btn login-required">Book Now</a>
            </div>

            <div class="room-item items extra">
                <img src="../../IMG/room_5.jpg" alt="Twin Room">
                <h3>Twin Room</h3>
                <p>Two comfortable single beds, perfect for friends or colleagues.</p>
                <a href="#" class="btn login-required">Book Now</a>
            </div>

            <div class="room-item extra">
                <img src="../../IMG/room_6.jpg" alt="Presidential Suite">
                <h3>Presidential Suite</h3>
                <p>Ultimate luxury with a private lounge and exclusive services.</p>
                <a href="#" class="btn login-required">Book Now</a>
            </div>

            <div class="room-item extra">
                <img src="../../IMG/room_7.jpg" alt="Penthouse">
                <h3>Penthouse</h3>
                <p>Top-floor penthouse with panoramic views and premium facilities.</p>
                <a href="#" class="btn login-required">Book Now</a>
            </div>
        </div>

        <div class="room-toggle">
            <button id="toggleRoomsBtn" class="btn">Show More</button>
        </div>
    </section>

    <section class="event-place">
        <h2 class="section-title">Event Place</h2>
        <p class="section-subtitle">Host your memorable events with us at AMV Hotel.</p>

        <div class="event-card">
            <img src="../../IMG/room_1.jpg" alt="Event Place">
            <div class="event-overlay">
                <h3>Grand Event Hall</h3>
                <p>
                    Perfect for weddings, conferences, and special gatherings.
                    Spacious, elegant, and equipped with modern facilities.
                </p>
                <p class="contact-number">📞 +63 912 345 6789</p>
                <a href="#" class="btn login-required">Contact Us Now</a>
            </div>
        </div>
    </section>

    <footer>
        <p>&copy; 2025 AMV Hotel. All rights reserved.</p>
    </footer>

    <div id="loginModal" class="modal">
        <div class="modal-content">
            <i class="fa-solid fa-lock fa-2x" style="color: #f1c40f; margin-bottom: 15px;"></i>
            <h2>Action Required</h2>
            <p>You must **sign in** to proceed with booking, ordering, or contacting us.</p>
            <div class="modal-buttons">
                <button class="btn close-btn" onclick="document.getElementById('loginModal').style.display='none'">
                    Cancel
                </button>
                <a href="login.php" class="btn">
                    Sign In
                </a>
            </div>
        </div>
    </div>


    <script src="../SCRIPT/signin_page.js"></script>
</body>

</html>