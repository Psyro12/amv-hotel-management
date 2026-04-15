<?php
session_start();
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require 'db_connect.php';
    $birthdate = $_POST['birthdate'];
    $address = $_POST['full_address']; // Use the concatenated full address
    $phone_no = $_POST['phone_no'];
    $email = $_SESSION['user_email'];
    $name = $_SESSION['user_name'];

    $update = "UPDATE user_info SET birthdate=?, address=?, phone_no=? WHERE user_email=?";
    $stmt = $conn->prepare($update);
    $stmt->bind_param("ssss", $birthdate, $address, $phone_no, $email);
    $stmt->execute();

    $birthdate = $_POST['birthdate'];
    $today = new DateTime();
    $birth = new DateTime($birthdate);
    $age = $today->diff($birth)->y;

    if ($age < 18) {
        die("Invalid age. You must be at least 18 years old.");
    }


    if (!preg_match("/^(09\d{9}|(\+639)\d{9})$/", $phone_no)) {
        die("Invalid phone number format.");
    }


    // Redirect to dashboard or home
    header("Location: index.php");
    exit();
}


?>
<!DOCTYPE html>
<html>

<head>
    <title>Complete Your Information</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../STYLE/complete-info-style.css">
    <link rel="stylesheet" href="../STYLE/utilities.css">
</head>

<body>

    <!-- Logo + Text -->
    <div class="logo-container">
        <img src="../../IMG/5.png" alt="AMV Logo">
        <div class="logo-text">
            <span>AMV</span>
            <span>Hotel</span>
        </div>
    </div>

    <div class="info-container">
        <h2 class="fs-lg">Please complete your information</h2>
        <form method="post">
            <div class="mb-2">
            <label>Birthdate:
                <input type="date" name="birthdate" id="birthdate" required>
            </label>
            </div>

            <div class="mb-2">
            <label>Address:</label>
            <div class="d-grid grid-cols-4 g-2">
                <select id="region">
                    <option value="">Select Region</option>
                </select>
                <select id="province" disabled>
                    <option value="">Select Province</option>
                </select>
                <select id="municipality" disabled>
                    <option value="">Select Municipality</option>
                </select>
                <select id="barangay" disabled>
                    <option value="">Select Barangay</option>
                </select>
                <input type="hidden" name="full_address" id="full_address">
            </div>
            </div>
            <div class="mb-2">
            <label>Phone Number:
                <input type="text" name="phone_no" required placeholder="Enter your phone number"
                    pattern="^(09\d{9}|(\+639)\d{9})$"
                    title="Enter a valid PH number (e.g. 09123456789 or +639123456789)">
            </label>
            </div>

            <button type="submit">Submit</button>
        </form>
    </div>

    <script>
        async function loadRegions() {
            const res = await fetch("https://psgc.gitlab.io/api/regions/");
            const data = await res.json();
            populateDropdown(document.getElementById("region"), data);
        }

        function populateDropdown(select, data) {
            data.forEach(item => {
                let opt = document.createElement("option");
                opt.value = item.code;
                opt.textContent = item.name;
                opt.dataset.name = item.name;
                select.appendChild(opt);
            });
        }

        async function loadProvinces(regionCode) {
            const res = await fetch("https://psgc.gitlab.io/api/provinces/");
            const data = await res.json();
            return data.filter(p => p.regionCode === regionCode);
        }

        async function loadMunicipalities(provinceCode) {
            const res = await fetch("https://psgc.gitlab.io/api/cities-municipalities/");
            const data = await res.json();
            return data.filter(m => m.provinceCode === provinceCode);
        }

        async function loadBarangays(muniCode) {
            const res = await fetch("https://psgc.gitlab.io/api/barangays/");
            const data = await res.json();
            return data.filter(b => b.municipalityCode === muniCode);
        }

        function resetDropdown(id, placeholder) {
            const select = document.getElementById(id);
            select.innerHTML = `<option value="">${placeholder}</option>`;
            select.disabled = true;
            return select;
        }

        function updateHiddenInput() {
            const region = document.getElementById("region");
            const province = document.getElementById("province");
            const muni = document.getElementById("municipality");
            const brgy = document.getElementById("barangay");

            const fullAddress = [
                region.options[region.selectedIndex]?.dataset.name || "",
                province.options[province.selectedIndex]?.dataset.name || "",
                muni.options[muni.selectedIndex]?.dataset.name || "",
                brgy.options[brgy.selectedIndex]?.dataset.name || ""
            ].filter(Boolean).join(", ");

            document.getElementById("full_address").value = fullAddress;
        }

        document.getElementById("region").addEventListener("change", async function () {
            const provinces = await loadProvinces(this.value);
            const provinceSelect = resetDropdown("province", "Select Province");
            resetDropdown("municipality", "Select Municipality");
            resetDropdown("barangay", "Select Barangay");

            if (provinces.length) {
                populateDropdown(provinceSelect, provinces);
                provinceSelect.disabled = false;
            }
            updateHiddenInput();
        });

        document.getElementById("province").addEventListener("change", async function () {
            const municipalities = await loadMunicipalities(this.value);
            const muniSelect = resetDropdown("municipality", "Select Municipality");
            resetDropdown("barangay", "Select Barangay");

            if (municipalities.length) {
                populateDropdown(muniSelect, municipalities);
                muniSelect.disabled = false;
            }
            updateHiddenInput();
        });

        document.getElementById("municipality").addEventListener("change", async function () {
            const barangays = await loadBarangays(this.value);
            const brgySelect = resetDropdown("barangay", "Select Barangay");

            if (barangays.length) {
                populateDropdown(brgySelect, barangays);
                brgySelect.disabled = false;
            }
            updateHiddenInput();
        });

        document.getElementById("barangay").addEventListener("change", function () {
            updateHiddenInput();
        });

        loadRegions();

        document.querySelector("form").addEventListener("submit", function (e) {
            const birthdate = document.getElementById("birthdate").value;
            const today = new Date();
            const birth = new Date(birthdate);

            let age = today.getFullYear() - birth.getFullYear();
            const m = today.getMonth() - birth.getMonth();
            if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) {
                age--; // adjust if birthday hasn't happened yet this year
            }

            if (age < 18) {
                e.preventDefault(); // stop form submission
                alert("You must be at least 18 years old to continue.");
            }
        });
    </script>
</body>

</html>