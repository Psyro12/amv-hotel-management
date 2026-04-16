# Database Updates Documentation

This document records the manual changes and structural updates made to the AMV Hotel Management System database (`amv_db`) by the Gemini AI agent.

## April 16, 2026

### 1. `bookings` Table Updates
*   **Column:** `rejection_reason` (New)
    *   **Type:** `TEXT`
    *   **Purpose:** Stores the reason provided by the administrator when a booking verification is rejected/cancelled. This information is also sent to the guest via email and in-app notifications.
*   **Column Type Change:** `status`
    *   **Original Type:** `ENUM('pending', 'confirmed', 'cancelled', 'checked_in', 'checked_out')` (approximate)
    *   **New Type:** `VARCHAR(50)`
    *   **Reason:** To remove ENUM restrictions, allowing for more flexible status tracking (e.g., specific cancellation states) without requiring table schema changes for every new status.
*   **Column Type Change:** `payment_term`
    *   **Original Type:** `ENUM('full', 'partial')`
    *   **New Type:** `VARCHAR(50)`
    *   **Reason:** Provides flexibility for future payment plans or specific payment terms that might not fit the original binary choice.

### 2. `transactions` Table Updates
*   **Column Type Change:** `transaction_type`
    *   **Original Type:** `ENUM('Booking', 'Food Order', 'Service')`
    *   **New Type:** `VARCHAR(50)`
    *   **Reason:** Allows the system to record a wider variety of transaction types without schema constraints.
*   **Column Type Change:** `status`
    *   **Original Type:** `VARCHAR(50)` (Ensured)
    *   **Update:** Explicitly verified and standardized as `VARCHAR(50)` to support custom statuses like:
        *   `Partially Paid`
        *   `Rescheduled`
        *   `Extended - Fully Paid`
        *   `Extended - Partially Paid`

## Implementation Notes
These changes were applied via `ALTER TABLE` commands. The application logic (PHP and JavaScript) has been updated to handle these strings correctly, including the implementation of "Indigo" branding for extension-related statuses and "Purple" branding for rescheduled bookings in the admin dashboard.
