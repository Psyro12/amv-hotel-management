<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMV Hotel</title>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
  <!-- Font Awesome Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="../STYLE/home_page.css">
  <link rel="stylesheet" href="../STYLE/utilities.css">
</head>
<style>
  /* Container */
  .profile-dropdown {
    position: relative;
    display: inline-block;
  }

  /* Dropdown box */
  .profile-menu {
    display: none;
    /* hidden by default */
    position: absolute;
    top: 75px;
    /* below the profile icon */
    right: 10px;
    background: #fff;
    color: #333;
    min-width: 280px;
    padding: 15px;
    border-radius: 10px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    z-index: 1000;
  }

  /* Text inside */
  .profile-menu p {
    margin: 5px 0;
    font-size: 14px;
  }

  /* Logout button */
  .profile-menu .logout-btn {
    margin-top: 30px;
    width: 100%;
    background: #c0392b;
    color: #fff;
    border: none;
    padding: 8px;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.3s;
  }

  .profile-menu .logout-btn:hover {
    background: #e74c3c;
  }
</style>

<body>
  <!-- Header -->
  <header>
    <!-- Left Side -->
    <div class="logo-container">
      <img src="../../IMG/5.png" alt="AMV Logo">
      <div class="logo-text">
        <span>AMV</span>
        <span>Hotel</span>
      </div>
    </div>

    <!-- Right Side -->
    <nav>
      <a href="#">Your Reservations</a>

      <!-- Normal Icons (Desktop) -->
      <div class="nav-icons desktop-icons">
        <div class="icon-circle" id="profileIcon"><i class="fa-solid fa-user"></i></div>
        <div class="icon-circle"><i class="fa-solid fa-envelope"></i></div>
        <div class="icon-circle"><i class="fa-solid fa-bell"></i></div>
      </div>

      <!-- Burger Menu (Mobile) -->
      <div class="burger-menu">
        <i class="fa-solid fa-bars"></i>
        <div class="dropdown px-2">
          <a href="#"><i class="fa-solid fa-user"></i> Profile</a>
          <a href="#"><i class="fa-solid fa-envelope"></i> Messages</a>
          <a href="#"><i class="fa-solid fa-bell"></i> Notifications</a>
        </div>
      </div>
    </nav>


    <!-- Hidden dropdown content -->
    <div class="profile-menu" id="profileMenu">
      <p><strong>Username:</strong> JohnDoe</p>
      <p><strong>Email:</strong> johndoe@email.com</p>
      <form action="../PHP/logout.php" method="POST">
        <button type="submit" class="btn logout-btn">Logout</button>
      </form>
    </div>
  </header>


  <!-- Hero Section -->
  <section class="hero">
    <div class="hero-content">
      <h1>Welcome to AMV Hotel</h1>
      <p>Experience comfort and luxury in the heart of the city. Book your stay with us today!</p>
      <a href="#" class="btn">Book Now</a>
    </div>
  </section>




  <!-- Introduction Section -->
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

  <!-- Features Section -->
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


  <!-- Foods Section -->
  <section class="foods">
    <h2 class="section-title">Our Specialties</h2>
    <p class="section-subtitle">Delight in our hotel’s signature dishes prepared by top chefs.</p>

    <div class="food-grid">
      <!-- First 4 visible foods -->
      <div class="food-item">
        <img src="../../IMG/food_1.jpg" alt="Grilled Salmon">
        <h3>Grilled Salmon</h3>
        <p>Freshly grilled salmon with herbs and lemon butter sauce.</p>
        <span class="price">₱450</span>
      </div>

      <div class="food-item">
        <img src="../../IMG/food_2.jpg" alt="Steak & Fries">
        <h3>Steak & Fries</h3>
        <p>Juicy sirloin steak served with crispy golden fries.</p>
        <span class="price">₱850</span>
      </div>

      <div class="food-item">
        <img src="../../IMG/food_3.jpg" alt="Pasta Primavera">
        <h3>Pasta Primavera</h3>
        <p>Italian-style pasta tossed with fresh vegetables and parmesan.</p>
        <span class="price">₱380</span>
      </div>

      <div class="food-item">
        <img src="../../IMG/food_4.JPG" alt="Cheesecake">
        <h3>Classic Cheesecake</h3>
        <p>Creamy cheesecake with a buttery crust and strawberry topping.</p>
        <span class="price">₱250</span>
      </div>

      <!-- Extra hidden foods -->
      <div class="food-item extra">
        <img src="../../IMG/food_5.jpg" alt="Caesar Salad">
        <h3>Caesar Salad</h3>
        <p>Crisp romaine lettuce with parmesan and creamy Caesar dressing.</p>
        <span class="price">₱300</span>
      </div>

      <div class="food-item extra">
        <img src="../../IMG/food_6.jpg" alt="Chicken Adobo">
        <h3>Chicken Adobo</h3>
        <p>Classic Filipino dish simmered in soy sauce, vinegar, and garlic.</p>
        <span class="price">₱280</span>
      </div>

      <div class="food-item extra">
        <img src="../../IMG/food_7.jpg" alt="Burger & Fries">
        <h3>Burger & Fries</h3>
        <p>Juicy beef burger with cheese, lettuce, and crispy fries.</p>
        <span class="price">₱320</span>
      </div>

      <div class="food-item extra">
        <img src="../../IMG/food_8.jpg" alt="Chocolate Lava Cake">
        <h3>Chocolate Lava Cake</h3>
        <p>Warm chocolate cake with a gooey molten center.</p>
        <span class="price">₱280</span>
      </div>
    </div>

    <!-- Button directly below food grid -->
    <div class="food-toggle">
      <button id="toggleFoodsBtn" class="btn">Show More</button>
    </div>
  </section>



  <!-- Rooms Section -->
  <section class="rooms">
    <h2 class="section-title">Our Rooms</h2>
    <p class="section-subtitle">Choose from our selection of comfortable and stylish rooms.</p>

    <div class="room-grid">
      <!-- First 4 visible rooms -->
      <div class="room-item">
        <img src="../../IMG/room_1.jpg" alt="Deluxe Room">
        <h3>Deluxe Room</h3>
        <p>Spacious room with a queen-size bed, perfect for couples.</p>
        <a href="#" class="btn">Book Now</a>
      </div>

      <div class="room-item">
        <img src="../../IMG/room_2.jpg" alt="Executive Suite">
        <h3>Executive Suite</h3>
        <p>Luxury suite with city views and modern amenities.</p>
        <a href="#" class="btn">Book Now</a>
      </div>

      <div class="room-item">
        <img src="../../IMG/room_3.jpg" alt="Family Room">
        <h3>Family Room</h3>
        <p>Comfortable room designed for families with children.</p>
        <a href="#" class="btn">Book Now</a>
      </div>

      <div class="room-item">
        <img src="../../IMG/room_4.jpg" alt="Single Room">
        <h3>Single Room</h3>
        <p>Cozy room ideal for solo travelers on business or leisure.</p>
        <a href="#" class="btn">Book Now</a>
      </div>

      <!-- Extra (hidden initially) -->
      <div class="room-item items extra">
        <img src="../../IMG/room_5.jpg" alt="Twin Room">
        <h3>Twin Room</h3>
        <p>Two comfortable single beds, perfect for friends or colleagues.</p>
        <a href="#" class="btn">Book Now</a>
      </div>

      <div class="room-item extra">
        <img src="../../IMG/room_6.jpg" alt="Presidential Suite">
        <h3>Presidential Suite</h3>
        <p>Ultimate luxury with a private lounge and exclusive services.</p>
        <a href="#" class="btn">Book Now</a>
      </div>

      <div class="room-item extra">
        <img src="../../IMG/room_7.jpg" alt="Penthouse">
        <h3>Penthouse</h3>
        <p>Top-floor penthouse with panoramic views and premium facilities.</p>
        <a href="#" class="btn">Book Now</a>
      </div>
    </div>

    <!-- Button directly below room grid -->
    <div class="room-toggle">
      <button id="toggleRoomsBtn" class="btn">Show More</button>
    </div>
  </section>

  <!-- Event Place Section -->
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
        <a href="#" class="btn">Contact Us Now</a>
      </div>
    </div>
  </section>



  <!-- Footer -->
  <footer>
    <p>&copy; 2025 AMV Hotel. All rights reserved.</p>
  </footer>

  <!-- script for home -->
  <script src="../SCRIPT/login-script.js"></script>
</body>
<script>
  const profileIcon = document.getElementById('profileIcon');
  const profileMenu = document.getElementById('profileMenu');

  profileIcon.addEventListener('click', () => {
    profileMenu.style.display = (profileMenu.style.display === "block") ? "none" : "block";
  });

  // Optional: click outside to close
  document.addEventListener('click', (e) => {
    if (!profileIcon.contains(e.target) && !profileMenu.contains(e.target)) {
      profileMenu.style.display = "none";
    }
  });
</script>

</html>