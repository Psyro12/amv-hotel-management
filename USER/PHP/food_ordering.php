<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Order Food - AMV Hotel</title>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="../STYLE/food_ordering.css">
</head>
<body>
  <!-- Header -->
  <header>
    <div class="container header-content">
      <div class="logo-container">
        <img src="../../IMG/5.png" alt="AMV Logo">
        <div class="logo-text">
          <span>AMV</span>
          <span>Hotel</span>
        </div>
      </div>
      <nav>
        <a href="index.php" class="btn">Back to Home</a>
      </nav>
    </div>
  </header>

  <!-- Breadcrumb -->
  <div class="breadcrumb container">
    <a href="index.php">Home</a> > <a href="#foods">Foods</a> > Grilled Salmon
  </div>

  <!-- Main Content -->
  <div class="container">
    <div class="ordering-container">
      <!-- Food Details -->
      <div class="food-details">
        <div class="food-image">
          <img src="../../IMG/food_1.jpg" alt="Grilled Salmon" id="foodImage">
        </div>
        
        <div class="food-info">
          <h1 id="foodName">Grilled Salmon</h1>
          <p class="food-description" id="foodDescription">
            Freshly grilled salmon with herbs and lemon butter sauce. Served with seasonal vegetables and your choice of side.
          </p>
          
          <div class="price-section">
            <div class="price" id="foodPrice">₱450</div>
            <div class="rating">
              <span class="stars">
                <i class="fas fa-star"></i>
                <i class="fas fa-star"></i>
                <i class="fas fa-star"></i>
                <i class="fas fa-star"></i>
                <i class="fas fa-star-half-alt"></i>
              </span>
              <span class="rating-text">4.5 (128 reviews)</span>
            </div>
          </div>
          
          <div class="nutrition-info">
            <h3>Nutrition Information</h3>
            <div class="nutrition-grid">
              <div class="nutrition-item">
                <span class="label">Calories</span>
                <span class="value">420 kcal</span>
              </div>
              <div class="nutrition-item">
                <span class="label">Protein</span>
                <span class="value">35g</span>
              </div>
              <div class="nutrition-item">
                <span class="label">Carbs</span>
                <span class="value">12g</span>
              </div>
              <div class="nutrition-item">
                <span class="label">Fat</span>
                <span class="value">28g</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Ordering Form -->
      <div class="ordering-form-container">
        <h2>Customize Your Order</h2>
        <form id="orderingForm">
          <!-- Quantity -->
          <div class="form-group">
            <label for="quantity">Quantity</label>
            <div class="quantity-selector">
              <button type="button" class="quantity-btn" id="decreaseQty">-</button>
              <input type="number" id="quantity" name="quantity" value="1" min="1" max="10">
              <button type="button" class="quantity-btn" id="increaseQty">+</button>
            </div>
          </div>

          <!-- Cooking Preference -->
          <div class="form-group">
            <label for="cookingPreference">Cooking Preference</label>
            <select id="cookingPreference" name="cookingPreference">
              <option value="medium">Medium</option>
              <option value="medium-rare">Medium Rare</option>
              <option value="well-done">Well Done</option>
              <option value="rare">Rare</option>
            </select>
          </div>

          <!-- Sauce Selection -->
          <div class="form-group">
            <label>Sauce Selection</label>
            <div class="checkbox-group">
              <label class="checkbox-item">
                <input type="radio" name="sauce" value="lemon-butter" checked>
                <span class="checkmark"></span>
                Lemon Butter Sauce
              </label>
              <label class="checkbox-item">
                <input type="radio" name="sauce" value="garlic-herb">
                <span class="checkmark"></span>
                Garlic Herb Sauce
              </label>
              <label class="checkbox-item">
                <input type="radio" name="sauce" value="teriyaki">
                <span class="checkmark"></span>
                Teriyaki Glaze
              </label>
              <label class="checkbox-item">
                <input type="radio" name="sauce" value="none">
                <span class="checkmark"></span>
                No Sauce
              </label>
            </div>
          </div>

          <!-- Side Dishes -->
          <div class="form-group">
            <label>Side Dishes</label>
            <div class="checkbox-group">
              <label class="checkbox-item">
                <input type="checkbox" name="sides" value="steamed-rice">
                <span class="checkmark"></span>
                Steamed Rice (+₱50)
              </label>
              <label class="checkbox-item">
                <input type="checkbox" name="sides" value="mashed-potato">
                <span class="checkmark"></span>
                Mashed Potato (+₱60)
              </label>
              <label class="checkbox-item">
                <input type="checkbox" name="sides" value="grilled-vegetables">
                <span class="checkmark"></span>
                Grilled Vegetables (+₱40)
              </label>
              <label class="checkbox-item">
                <input type="checkbox" name="sides" value="french-fries">
                <span class="checkmark"></span>
                French Fries (+₱55)
              </label>
            </div>
          </div>

          <!-- Special Instructions -->
          <div class="form-group">
            <label for="specialInstructions">Special Instructions</label>
            <textarea id="specialInstructions" name="specialInstructions" placeholder="Any special requests or dietary restrictions..."></textarea>
          </div>

          <!-- Delivery Options -->
          <div class="form-group">
            <label>Delivery Option</label>
            <div class="radio-group">
              <label class="radio-item">
                <input type="radio" name="delivery" value="room" checked>
                <span class="radiomark"></span>
                Deliver to Room
              </label>
              <label class="radio-item">
                <input type="radio" name="delivery" value="pickup">
                <span class="radiomark"></span>
                Pick up at Restaurant
              </label>
            </div>
          </div>

          <!-- Room Number (if room delivery selected) -->
          <div class="form-group" id="roomNumberGroup">
            <label for="roomNumber">Room Number</label>
            <input type="text" id="roomNumber" name="roomNumber" placeholder="Enter your room number">
          </div>

          <!-- Order Summary -->
          <div class="order-summary">
            <h3>Order Summary</h3>
            <div class="summary-item">
              <span>Grilled Salmon x <span id="summaryQuantity">1</span></span>
              <span id="summaryBasePrice">₱450</span>
            </div>
            <div id="extrasList"></div>
            <div class="summary-total">
              <span>Total</span>
              <span id="summaryTotal">₱450</span>
            </div>
          </div>

          <!-- Order Buttons -->
          <div class="order-buttons">
            <button type="submit" class="btn btn-primary btn-order">Place Order</button>
            <button type="button" class="btn btn-secondary" id="addToCart">Add to Cart</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Related Foods Section -->
    <div class="related-foods">
      <h2>You Might Also Like</h2>
      <div class="related-grid">
        <div class="related-item">
          <img src="../../IMG/food_2.jpg" alt="Steak & Fries">
          <h3>Steak & Fries</h3>
          <p class="related-price">₱850</p>
          <button class="btn btn-sm">View Details</button>
        </div>
        
        <div class="related-item">
          <img src="../../IMG/food_3.jpg" alt="Pasta Primavera">
          <h3>Pasta Primavera</h3>
          <p class="related-price">₱380</p>
          <button class="btn btn-sm">View Details</button>
        </div>
        
        <div class="related-item">
          <img src="../../IMG/food_4.JPG" alt="Cheesecake">
          <h3>Classic Cheesecake</h3>
          <p class="related-price">₱250</p>
          <button class="btn btn-sm">View Details</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Footer -->
  <footer>
    <div class="container">
      <p>&copy; 2025 AMV Hotel. All rights reserved.</p>
    </div>
  </footer>

  <!-- JavaScript -->
  <script src="../SCRIPT/food_ordering.js"></script>
</body>
</html>