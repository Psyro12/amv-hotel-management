# AMV Hotel Management System - System Updates Log

This document serves as a permanent record of all functional, structural, and design updates applied to the system, ensuring full transparency of changes made to both the application logic and the database.

---

## 🟢 April 16, 2026 - Major Functional & UI Enhancements

### 1. Booking Verification & Rejection System
*   **Source-Based Notifications:** Implemented logic to distinguish between website and mobile app bookings. 
    *   **Website Guests:** Continue to receive automated emails for check-ins, check-outs, extensions, and rejections.
    *   **App Guests (P2P):** Now exclusively receive in-app notifications, preventing redundant emails for mobile-first users.
*   **Booking Date Visibility:** Added check-in and check-out dates to the pending verification cards in the admin drawer. This allows administrators to clearly distinguish between different bookings for the same room on different dates.
*   **Status Differentiation:** Introduced the `rejected` status to distinguish between guest-initiated cancellations and administrator denials.
*   **Enhanced Rejection Modal:** 
    *   Replaced the standard dropdown with an interactive **Suggested Reason Button Grid**.
    *   Integrated a **Smart Toggle** system: Clicking a reason (e.g., "Invalid Payment") auto-fills the message to the guest; clicking it again clears it.
    *   Added a high-priority `z-index` (999999) to ensure the modal always appears above drawers.
    *   Aligned the design with the gold/gray AMV branding.
*   **Guest Communication:** Automated rejection emails and in-app notifications now include the specific reason provided by the admin.

### 2. Transaction History & Tracking
*   **Performance Optimization:** Significantly optimized the transaction fetch query by removing expensive `UNION ALL` operations and simplifying the count logic. This resolves issues with slow loading times and "System Errors" encountered during high-volume usage.
*   **Polling Frequency Adjustment:** Reduced the background refresh rate for the transaction table from 1 second to 5 seconds. This reduces server load and prevents overlapping requests that were causing instability when switching between dashboard tabs.
*   **Partial Payment Fix:** Resolved a bug where web bookings with a "partial" payment term were being incorrectly recorded as "Paid" upon admin approval. The system now correctly identifies the payment term and records it as "Partially Paid" in the history.
*   **Pending Visibility:** Updated the transaction history query to prioritize the **"Pending"** status. This ensures that new web bookings correctly show as "Pending" even if they have a partial payment plan selected, only switching to "Partially Paid" once verified by an admin.
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

## 🟢 April 18, 2026 - Push Notification & Real-Time Update Integration

### 1. Centralized Notification System (FCM Integration)
*   **Centralized Helper:** Migrated all push notification logic to a dedicated `notification_helper.php` file. This ensures consistent handling of database logging and Firebase Cloud Messaging (FCM) across the entire admin dashboard.
*   **FCM HTTP v1 Support:** Implemented the modern Firebase Cloud Messaging HTTP v1 API for more secure and reliable push notifications to the Guest App.
*   **Auto-Authentication:** Added a pure PHP OAuth2 token generator to handle Google Service Account authentication without requiring external heavy dependencies.

### 2. Food Order Lifecycle Enhancements
*   **Real-Time Guest Alerts:** Guests now receive instant push notifications when their food order status changes:
    *   **Preparing:** Titled "Kitchen Update" to inform guests their meal is being cooked.
    *   **Being Served:** Updated from "Served" to **"Order Being Served"** with a more accurate message: *"Your food order is being served. Please wait for a moment."* This reflects that the food is currently in transit to their room.
    *   **Cancelled:** Titled "Order Cancelled" to notify guests of any issues.
*   **Transaction Sync:** Added logic to `update_order.php` to automatically mark the corresponding transaction as **'Rejected'** if a food order is cancelled. This ensures the financial history stays accurate if a kitchen-level cancellation occurs.
*   **Dashboard Sync:** Integrated `system_updates` pings to ensure that when an order is updated, the admin dashboard's "Food Orders" and "Notifications" tabs refresh in real-time for all logged-in staff.

### 3. Automated Booking & Stay Alerts
*   **Checkout Due Notifications:** Integrated FCM push notifications into the `trigger_checkout_alerts.php` cron/background task. 
    *   **Mobile Guests:** Now receive a push notification if they haven't checked out by 12:00 PM, prompting them to visit the front desk for checkout or extension.
*   **Reschedule Notifications:** Refactored `guest_reschedule.php` to use the centralized `sendAppNotification` helper, ensuring mobile guests receive a push alert confirming their new dates and room assignments.
*   **Arrival Status Notifications:** Standardized `update_arrival.php` to use the centralized helper for check-ins, check-outs, and rejections, ensuring a unified notification experience for mobile app users.

### 4. Technical Refactoring
*   **Code Consolidation:** Removed manual `INSERT INTO guest_notifications` blocks across multiple files (`update_order.php`, `guest_reschedule.php`, `trigger_checkout_alerts.php`), replacing them with calls to the `sendAppNotification()` function. This significantly reduces code duplication and potential for bugs.
### 5. Notification Logic Refinements (April 18, 2026 - Part 2)
*   **Source-Based Routing:** Standardized all booking-related notifications (`approve_booking.php`, `update_arrival.php`, `guest_reschedule.php`) to use `booking_source` as the primary filter:
    *   **mobile_app:** Exclusively receives real-time FCM push notifications.
    *   **online / reservation / walk-in:** Receives professional HTML emails via PHPMailer.
*   **Comprehensive Coverage:** Fixed missing email triggers for "No-Show" and "Stay Extension" actions for web/admin guests in `update_arrival.php`.
*   **Admin-Created Bookings:** Explicitly ensured that bookings created by administrators (Reservations/Walk-ins) maintain their legacy email confirmation behavior while benefiting from the new high-signal HTML templates.
### 6. Critical Refinements & Bug Fixes (April 18, 2026 - Part 3)
*   **JSON Response Stability:** Implemented strict error suppression (`error_reporting(0)`) in `update_arrival.php` and `approve_booking.php`. This prevents background PHP warnings (often caused by SMTP connection notices) from being printed and corrupting the JSON payload, resolving the "Unexpected token <" error in the admin dashboard.
*   **Logical Status Differentiation:**
    *   **Denying Pending:** Now correctly labeled as **"Rejected"** in both the database and guest notifications.
    *   **Cancelling Confirmed:** Now correctly labeled as **"Cancelled"** (status: `cancelled`) to distinguish it from initial rejections.
    *   **Messaging:** Dynamic template logic ensures guests receive a "Booking Rejected" message if they were never verified, but a "Booking Cancelled" message if their confirmed reservation was terminated.
*   **Contact Info Integrity:** Restored the database-driven hotel contact block in all email templates. The hotel's address, phone, and support email are now dynamically fetched from the `admin_user` table, ensuring guests always have access to up-to-date support information.
*   **Mobile Sync Fix (Rescheduling):** Patched a flaw in `guest_reschedule.php` where mobile app users were receiving redundant emails. The file now queries `booking_source` and correctly routes confirmations to **Push Notifications only** for mobile users, while maintaining emails for web/admin users.

