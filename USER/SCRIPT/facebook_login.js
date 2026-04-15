import { auth, fbProvider } from "./firebase_config.js";
import { signInWithPopup } from "https://www.gstatic.com/firebasejs/10.14.0/firebase-auth.js";

document.getElementById("fbBtn").onclick = () => {

   signInWithPopup(auth, fbProvider)
    .then(result => {
        const user = result.user;

        fetch("save_user.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                uid: user.uid,
                name: user.displayName,
                email: user.email,
                photo: user.photoURL
            })
        })
        .then(res => res.json())  // ← IMPORTANT!
        .then(data => {
            if (data.redirect) {
                window.location.href = data.redirect;  // ← REDIRECT
            } else {
                console.error("Server response:", data);
            }
        })
        .catch(error => console.error("Fetch error:", error));
    })
    .catch(err => console.error("Login error:", err));
};
