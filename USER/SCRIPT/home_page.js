//for burger menu
document.addEventListener("DOMContentLoaded", () => {
  const burger = document.querySelector(".burger-menu i");
  const dropdown = document.querySelector(".burger-menu .dropdown");

  burger.addEventListener("click", () => {
    dropdown.style.display = dropdown.style.display === "block" ? "none" : "block";
  });

  // Optional: close if clicked outside
  document.addEventListener("click", (e) => {
    if (!e.target.closest(".burger-menu")) {
      dropdown.style.display = "none";
    }
  });
});

//for profile dropdown
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

//for message dropdown
const messageIcon = document.getElementById('messageIcon');
const messageMenu = document.getElementById('messageMenu');

messageIcon.addEventListener('click', () => {
  messageMenu.style.display = (messageMenu.style.display === "block") ? "none" : "block";
});

// Optional: click outside to close
document.addEventListener('click', (e) => {
  if (!messageIcon.contains(e.target) && !messageMenu.contains(e.target)) {
    messageMenu.style.display = "none";
  }
});

//for notification dropdown
const notificationIcon = document.getElementById('notificationIcon');
const notificationMenu = document.getElementById('notificationMenu');

notificationIcon.addEventListener('click', () => {
  notificationMenu.style.display = (notificationMenu.style.display === "block") ? "none" : "block";
});

// Optional: click outside to close
document.addEventListener('click', (e) => {
  if (!notificationIcon.contains(e.target) && !notificationMenu.contains(e.target)) {
    notificationMenu.style.display = "none";
  }
});

//for rooms
const toggleBtn = document.getElementById("toggleRoomsBtn");
const extraRooms = document.querySelectorAll(".room-item.extra");

toggleBtn.addEventListener("click", function () {
  const isHidden = !extraRooms[0].classList.contains("show");

  if (isHidden) {
    extraRooms.forEach(room => room.classList.add("show"));
    toggleBtn.textContent = "Hide";
  } else {
    extraRooms.forEach(room => room.classList.remove("show"));
    toggleBtn.textContent = "Show More";

    // Smooth scroll back up to the section
    window.scrollTo({
      top: document.querySelector(".rooms").offsetTop,
      behavior: "smooth"
    });
  }
});

//for foods
const toggleFoodsBtn = document.getElementById("toggleFoodsBtn");
const extraFoods = document.querySelectorAll(".food-item.extra");

toggleFoodsBtn.addEventListener("click", function () {
  const isHidden = !extraFoods[0].classList.contains("show");

  if (isHidden) {
    extraFoods.forEach(food => food.classList.add("show"));
    toggleFoodsBtn.textContent = "Hide";
  } else {
    extraFoods.forEach(food => food.classList.remove("show"));
    toggleFoodsBtn.textContent = "Show More";

    // Smooth scroll back up to Foods section
    window.scrollTo({
      top: document.querySelector(".foods").offsetTop,
      behavior: "smooth"
    });
  }
});

// Scroll Down Indicator
document.querySelector(".scroll-indicator").addEventListener("click", () => {
  const introSection = document.querySelector(".intro");
  const offset = 65; // adjust this value (px) to reduce distance
  const top = introSection.getBoundingClientRect().top + window.pageYOffset - offset;

  window.scrollTo({ top, behavior: "smooth" });
});

// Smooth scroll with adjustable offset
document.addEventListener("DOMContentLoaded", () => {
  const bookNowBtn = document.querySelector(".hero .btn");
  const roomsSection = document.querySelector(".rooms");

  // 🔧 Adjustable offset in pixels (change as needed)
  let scrollOffset = 40;

  if (bookNowBtn && roomsSection) {
    bookNowBtn.addEventListener("click", (e) => {
      e.preventDefault();

      const elementPosition = roomsSection.getBoundingClientRect().top + window.scrollY;
      const offsetPosition = elementPosition - scrollOffset;

      window.scrollTo({
        top: offsetPosition,
        behavior: "smooth"
      });
    });
  }
});
