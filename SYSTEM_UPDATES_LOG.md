# AMV Hotel Management System - System Updates Log

This document serves as a permanent record of all functional, structural, and design updates applied to the system, ensuring full transparency of changes made to both the application logic and the database.

---

## 🟢 April 16, 2026 - Major Functional & UI Enhancements

### 1. Booking Verification & Rejection System
*   **Status Differentiation:** Introduced the `rejected` status to distinguish between guest-initiated cancellations and administrator denials.
*   **Enhanced Rejection Modal:** 
    *   Replaced the standard dropdown with an interactive **Suggested Reason Button Grid**.
    *   Integrated a **Smart Toggle** system: Clicking a reason (e.g., "Invalid Payment") auto-fills the message to the guest; clicking it again clears it.
    *   Added a high-priority `z-index` (999999) to ensure the modal always appears above drawers.
    *   Aligned the design with the gold/gray AMV branding.
*   **Guest Communication:** Automated rejection emails and in-app notifications now include the specific reason provided by the admin.

### 2. Transaction History & Tracking
*   **Partial Payment Fix:** Resolved a bug where web bookings with a "partial" payment term were being incorrectly recorded as "Paid" upon admin approval. The system now correctly identifies the payment term and records it as "Partially Paid" in the history.
*   **Pending Visibility:** Updated the transaction history query to include web bookings that are still awaiting admin verification. These now appear with a **"Pending"** status, ensuring the history is complete even before the payment is fully verified.
*   **New Transaction Types:** The system now automatically records transactions for:
    *   **Rescheduling:** Recorded as "Rescheduled" with the new total.
    *   **Extensions:** Recorded as "Extended - Fully Paid" or "Extended - Partially Paid" based on the payment choice.
*   **Data Integrity:**
    *   **User Attribution:** Fixed a bug where the admin's ID was being recorded instead of the guest's ID in the transaction history.
    *   **Payment Methods:** Transactions now correctly inherit the original `payment_method` from the booking instead of displaying "N/A".
    *   **Deduplication:** Implemented a 10-second server-side lockout to prevent duplicate/triple recording of transactions during extensions and reschedules.
*   **Visual Status Badges:** Added professional color-coding:
    *   **Rescheduled:** Purple theme.
    *   **Extended:** Indigo theme.
    *   **Partially Paid:** Professional Sky Blue/Slate theme.
    *   **Rejected:** Consistent Red theme (distinct from Cancelled).

### 3. User Experience (UX) Improvements
*   **Persistence Logic:** Success toast messages (SweetAlert2) are now stored in `sessionStorage`. This ensures that after the page reloads, the user still sees the confirmation message (e.g., "Stay Extended!").
*   **Reschedule Flow:** Always prompts for a final confirmation popup before applying any changes to the guest's dates or rooms.
*   **Pagination:** Implemented server-side pagination for the Payment Receipt Gallery to improve dashboard loading performance.

---

## 🗄️ Database Schema Updates

### 1. `bookings` Table
*   **New Column:** `rejection_reason` (TEXT) - Stores the admin's explanation for a denied booking.
*   **Flexibility Update:** Changed `status` and `payment_term` from `ENUM` to `VARCHAR(50)` to allow for future status types without schema locks.

### 2. `transactions` Table
*   **Flexibility Update:** Changed `transaction_type` to `VARCHAR(50)`.
*   **Standardization:** Ensured `status` is `VARCHAR(50)` to accommodate custom strings like "Extended - Fully Paid".

---

## 🛠️ Critical Bug Fixes & Refactoring
*   **Logic Consolidation:** Cleaned up `update_arrival.php` to remove redundant code blocks and multiple `exit` statements that were causing JSON syntax errors in the browser console.
*   **Syntax Correction:** Resolved `Unexpected token '}'` errors in `dashboard_scripts.js` caused by accidental code duplication during high-volume updates.
*   **Notification Triggers:** Added `system_updates` table pings to all new transaction points to ensure the dashboard UI refreshes in real-time for all administrators.
