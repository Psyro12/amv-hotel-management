// Food data
const foodData = {
    name: "Grilled Salmon",
    description: "Freshly grilled salmon with herbs and lemon butter sauce. Served with seasonal vegetables and your choice of side.",
    basePrice: 450,
    image: "../../IMG/food_1.jpg"
};

// Extras pricing
const extrasPricing = {
    sides: {
        'steamed-rice': 50,
        'mashed-potato': 60,
        'grilled-vegetables': 40,
        'french-fries': 55
    }
};

// DOM Elements
const quantityInput = document.getElementById('quantity');
const decreaseBtn = document.getElementById('decreaseQty');
const increaseBtn = document.getElementById('increaseQty');
const orderingForm = document.getElementById('orderingForm');
const summaryQuantity = document.getElementById('summaryQuantity');
const summaryBasePrice = document.getElementById('summaryBasePrice');
const summaryTotal = document.getElementById('summaryTotal');
const extrasList = document.getElementById('extrasList');
const roomNumberGroup = document.getElementById('roomNumberGroup');
const deliveryRadios = document.querySelectorAll('input[name="delivery"]');

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    // Set food data
    document.getElementById('foodName').textContent = foodData.name;
    document.getElementById('foodDescription').textContent = foodData.description;
    document.getElementById('foodPrice').textContent = `₱${foodData.basePrice}`;
    
    // Initialize order summary
    updateOrderSummary();
    
    // Set up event listeners
    setupEventListeners();
});

// Set up event listeners
function setupEventListeners() {
    // Quantity buttons
    decreaseBtn.addEventListener('click', decreaseQuantity);
    increaseBtn.addEventListener('click', increaseQuantity);
    quantityInput.addEventListener('change', updateOrderSummary);
    
    // Form elements that affect price
    const priceAffectingElements = document.querySelectorAll(
        'input[name="sides"], input[name="sauce"]'
    );
    priceAffectingElements.forEach(element => {
        element.addEventListener('change', updateOrderSummary);
    });
    
    // Delivery option change
    deliveryRadios.forEach(radio => {
        radio.addEventListener('change', toggleRoomNumber);
    });
    
    // Form submission
    orderingForm.addEventListener('submit', handleOrderSubmission);
    
    // Add to cart button
    document.getElementById('addToCart').addEventListener('click', addToCart);
}

// Quantity functions
function decreaseQuantity() {
    let currentValue = parseInt(quantityInput.value);
    if (currentValue > 1) {
        quantityInput.value = currentValue - 1;
        updateOrderSummary();
    }
}

function increaseQuantity() {
    let currentValue = parseInt(quantityInput.value);
    if (currentValue < 10) {
        quantityInput.value = currentValue + 1;
        updateOrderSummary();
    }
}

// Toggle room number field based on delivery option
function toggleRoomNumber() {
    const roomDeliverySelected = document.querySelector('input[name="delivery"]:checked').value === 'room';
    roomNumberGroup.style.display = roomDeliverySelected ? 'block' : 'none';
    
    if (!roomDeliverySelected) {
        document.getElementById('roomNumber').value = '';
    }
}

// Calculate and update order summary
function updateOrderSummary() {
    const quantity = parseInt(quantityInput.value);
    const basePrice = foodData.basePrice;
    
    // Calculate extras
    let extrasTotal = 0;
    let extrasHTML = '';
    
    // Check selected sides
    const selectedSides = document.querySelectorAll('input[name="sides"]:checked');
    selectedSides.forEach(side => {
        const sidePrice = extrasPricing.sides[side.value];
        extrasTotal += sidePrice;
        extrasHTML += `
            <div class="extra-item">
                <span>${getSideName(side.value)}</span>
                <span>+₱${sidePrice}</span>
            </div>
        `;
    });
    
    // Update summary display
    summaryQuantity.textContent = quantity;
    summaryBasePrice.textContent = `₱${basePrice * quantity}`;
    extrasList.innerHTML = extrasHTML;
    
    // Calculate and display total
    const total = (basePrice * quantity) + (extrasTotal * quantity);
    summaryTotal.textContent = `₱${total}`;
}

// Get display name for side
function getSideName(sideValue) {
    const sideNames = {
        'steamed-rice': 'Steamed Rice',
        'mashed-potato': 'Mashed Potato',
        'grilled-vegetables': 'Grilled Vegetables',
        'french-fries': 'French Fries'
    };
    return sideNames[sideValue] || sideValue;
}

// Handle order submission
function handleOrderSubmission(e) {
    e.preventDefault();
    
    // Get form data
    const formData = new FormData(orderingForm);
    const orderData = {
        food: foodData.name,
        quantity: parseInt(formData.get('quantity')),
        cookingPreference: formData.get('cookingPreference'),
        sauce: formData.get('sauce'),
        sides: formData.getAll('sides'),
        specialInstructions: formData.get('specialInstructions'),
        delivery: formData.get('delivery'),
        roomNumber: formData.get('roomNumber'),
        total: document.getElementById('summaryTotal').textContent
    };
    
    // Validate room number if room delivery is selected
    if (orderData.delivery === 'room' && !orderData.roomNumber) {
        alert('Please enter your room number for room delivery.');
        return;
    }
    
    // In a real application, you would send this data to the server
    console.log('Order submitted:', orderData);
    
    // Show confirmation
    showOrderConfirmation(orderData);
}

// Add to cart functionality
function addToCart() {
    // Get form data
    const formData = new FormData(orderingForm);
    const cartItem = {
        food: foodData.name,
        quantity: parseInt(formData.get('quantity')),
        cookingPreference: formData.get('cookingPreference'),
        sauce: formData.get('sauce'),
        sides: formData.getAll('sides'),
        specialInstructions: formData.get('specialInstructions'),
        price: foodData.basePrice,
        extras: calculateExtrasTotal(),
        total: document.getElementById('summaryTotal').textContent
    };
    
    // In a real application, you would add this to a cart in localStorage or send to server
    console.log('Added to cart:', cartItem);
    
    // Show confirmation
    alert(`${foodData.name} has been added to your cart!`);
}

// Calculate extras total
function calculateExtrasTotal() {
    let extrasTotal = 0;
    const selectedSides = document.querySelectorAll('input[name="sides"]:checked');
    selectedSides.forEach(side => {
        extrasTotal += extrasPricing.sides[side.value];
    });
    return extrasTotal;
}

// Show order confirmation
function showOrderConfirmation(orderData) {
    const deliveryText = orderData.delivery === 'room' 
        ? `to Room ${orderData.roomNumber}` 
        : 'for pickup at the restaurant';
    
    const message = `
        Order Confirmed!
        
        ${orderData.food} x ${orderData.quantity}
        ${orderData.sides.length > 0 ? 'With: ' + orderData.sides.map(getSideName).join(', ') : ''}
        ${orderData.specialInstructions ? 'Special Instructions: ' + orderData.specialInstructions : ''}
        
        Delivery: ${deliveryText}
        Total: ${orderData.total}
        
        Estimated preparation time: 20-30 minutes
        Thank you for your order!
    `;
    
    alert(message);
    
    // Reset form
    orderingForm.reset();
    quantityInput.value = 1;
    updateOrderSummary();
}

// Initialize room number visibility
toggleRoomNumber();