// firebase-config.js
import { initializeApp } from "https://www.gstatic.com/firebasejs/10.14.0/firebase-app.js";
import { getAuth, GoogleAuthProvider, FacebookAuthProvider, signInWithPopup } from "https://www.gstatic.com/firebasejs/10.14.0/firebase-auth.js";

const firebaseConfig = {
    apiKey: "AIzaSyABAgPtdOzCrmojYPE4yRdrmFGgM0egHcY",
    authDomain: "amv-project-a7f64.firebaseapp.com",
    projectId: "amv-project-a7f64",
    storageBucket: "amv-project-a7f64.firebasestorage.app",
    messagingSenderId: "40929229124",
    appId: "1:40929229124:web:19fc472883a0e723217f11",
};

// Initialize Firebase
const app = initializeApp(firebaseConfig);
export const auth = getAuth(app);
export const provider = new GoogleAuthProvider();
export const fbProvider = new FacebookAuthProvider();