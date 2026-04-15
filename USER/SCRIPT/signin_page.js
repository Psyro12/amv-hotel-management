
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

//New Script for Modal Functionality
document.addEventListener('DOMContentLoaded', function () {
    const loginRequiredElements = document.querySelectorAll('.login-required');
    const loginModal = document.getElementById('loginModal');
    const body = document.body; // Reference to the <body> element

    // Function to open the modal
    function openModal(e) {
        e.preventDefault();
        e.stopPropagation();
        loginModal.style.display = 'flex';
        body.classList.add('modal-open'); // 🔑 ADD CLASS TO BODY
    }

    // Function to close the modal (used by the Cancel button and Sign In link)
    function closeModal() {
        loginModal.style.display = 'none';
        body.classList.remove('modal-open'); // 🔑 REMOVE CLASS FROM BODY
    }

    loginRequiredElements.forEach(element => {
        element.addEventListener('click', openModal);
    });

    // Add the close function to the Cancel button (if it's present)
    const cancelButton = loginModal.querySelector('.close-btn');
    if (cancelButton) {
        cancelButton.onclick = closeModal;
    }

    // Add the close function to the Sign In button (if you navigate away, the class is cleared anyway, but this is clean)
    const signInButton = loginModal.querySelector('a.btn');
    if (signInButton) {
        signInButton.addEventListener('click', closeModal);
    }

    // NOTE: The window.addEventListener for clicking outside is still removed, 
    // ensuring only the buttons can dismiss the modal.
});

// The food/room toggle logic should remain in home_page.js
// and should still work since we used event.stopPropagation().