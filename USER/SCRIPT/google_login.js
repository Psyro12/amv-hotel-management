import { auth, provider } from "./firebase_config.js";
import { signInWithPopup } from "https://www.gstatic.com/firebasejs/10.14.0/firebase-auth.js";

document.getElementById("googleBtn").onclick = () => {

    signInWithPopup(auth, provider)
        .then((result) => {

            const user = result.user;

            // Send user data to PHP
            fetch("save_user.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    name: user.displayName,
                    email: user.email,
                    uid: user.uid,
                    photo: user.photoURL
                })
            })
            // CHANGE 1: Use .json() instead of .text() so JS treats the response as an object
            .then(res => {
                if (!res.ok) {
                    throw new Error("Network response was not ok");
                }
                return res.json();
            })
            .then(data => {
                // CHANGE 2: Check for the redirect key and navigate
                if (data.redirect) {
                    window.location.href = data.redirect;
                } else {
                    // Handle cases where the server didn't send a redirect
                    console.log("Server response:", data);
                    if(data.error) {
                        alert("Error: " + data.error);
                    }
                }
            })
            .catch(error => {
                console.error("Fetch error:", error);
                alert("An error occurred while saving user data.");
            });

        })
        .catch((error) => {
            console.error("Login Error:", error);
        });
};