# IEI Membership Plugin — Agent Instructions (Codex)

## Current Implementation Status (March 2026)
Implemented:
- Public application form shortcode with validation, attachment handling, notification to pre-approval officer.
- Post-submit application redirect to configurable Thank You page and thank-you template rendering.
- Expanded public application form fields and responsive layout (name/contact/address/signature/declaration + nomination conditional fields).
- Pre-approval and board workflow with director assignment, voting, reminder/reset actions, threshold auto-finalisation.
- Approval pipeline: WP user provisioning, pending-payment role, member row/subscription row creation, approval emails.
- Protected file storage + permission-gated stream endpoint (admin/pre-approval/assigned director).
- Director dashboard shortcode with list/detail, file preview/download, and voting.
- Payment admin queue and manual mark-paid activation flow.
- Payments admin list extended with default outstanding view, completed/all filters, search, status chips, and link to member detail.
- Payment activation email with welcome messaging and member-home/login link.
- Daily maintenance cron for overdue/lapsed transitions and role downgrade.
- CSV import for active members.
- Members admin list + detail view with latest subscription snapshot, payment history, and recent activity timeline.
- Configurable login redirects for directors, pending-payment users, and active/current members.
- Configurable next membership number counter with safe auto-increment.

Partially implemented / scaffold-only:
- Subscriptions admin page is currently scaffolded.
- Activity Log admin page is currently scaffolded.

## Purpose
Build a custom WordPress plugin that manages:
- Public membership applications (with secure file uploads)
- Pre-approval by Stuart (Pre-Approval Officer)
- Board voting by 12 directors (login + 2FA enforced by site)
- Auto-finalisation once approval/rejection thresholds are hit
- Payment flow (manual bank transfer in v1; Stripe OR PayPal pluggable later)
- Membership creation + yearly renewals (fixed July 1 cycle)
- Role-based access control compatible with the "Members" plugin
- Activity timeline logging for auditability

This plugin must store operational data in custom DB tables (NOT wp_posts/wp_postmeta), and use WP users for authentication.

## Core Rules
1. **No MemberPress dependency.** Ignore MemberPress.
2. **Do not depend on Gravity Forms.** Build the public form in-plugin.
3. **Use custom DB tables** for applications, votes, members, subscriptions, payments, activity logs, and files.
4. **Attachments are never public.**
   - Store in `/wp-content/protected-folder/iei-membership/` (configurable)
   - Never use `/wp-content/uploads/` for these documents
   - Provide file access via a permission-checked streaming controller only.
5. **Directors must login** (no magic auto-login links).
   - Site-level 2FA plugin enforces 2FA for director role.
6. **Create WP user on board approval** (not pre-approval).
   - Role after approval: `iei_pending_payment`
   - Role after payment: `iei_member`
7. **Renewals are yearly** and due on **July 1**.
   - If unpaid on July 1 => subscription becomes `overdue` but user keeps `iei_member` access
   - After grace period (default 30 days, configurable) => mark `lapsed` and downgrade role to `iei_pending_payment`
   - Existing member renewals are always full-year (no pro-rata).
8. **Pro-rata applies ONLY to new members**, calculated by month until June 30, unless within cutoff days.
   - If within cutoff days (default 15, configurable), charge full year and include next year.
9. **Approval voting auto-finalises** immediately when threshold hit:
   - approvals >= threshold => approved
   - rejections >= threshold => rejected
10. **Admin/Stuart can reset a director vote** back to `unanswered` (accidental response correction).
11. **Every important event must be logged** in `iei_activity_log` (application timeline).

## Roles & Capabilities
Create (or register) roles:
- `iei_preapproval_officer` (Stuart)
- `iei_director`
- `iei_member`
- `iei_pending_payment`

Admins can use WP Administrator and should have full access.
Prefer capability checks rather than role-name checks where possible.

## Admin Menus
Top-level menu: `IEI Membership`
Submenus:
- Applications
- Directors
- Members
- Subscriptions
- Payments
- Settings
- Activity Log
- Import Members (CSV)

Note:
- Members submenu is implemented (search/list/detail + activity snapshot).
- Subscriptions and Activity Log submenu pages remain scaffold placeholders.

## Public / Front-end Shortcodes
- `[iei_membership_application]` -> public application form (no login)
- `[iei_director_dashboard]` -> director portal (login required)
- `[iei_member_payment_portal]` -> payment/renewal portal for pending_payment/lapsed users (login required)

## Data Model (Custom DB Tables)
Use `$wpdb->prefix . 'iei_*'`.

Minimum tables:
- `iei_applications`
- `iei_application_files`
- `iei_application_votes`
- `iei_members`
- `iei_subscriptions`
- `iei_payments`
- `iei_activity_log`

All CRUD must use prepared SQL statements. Add indexes and unique constraints per spec.

Current `iei_applications` fields additionally include:
- `applicant_middle_name`
- `address_line_1`, `address_line_2`, `suburb`, `state`, `postcode`
- `phone`, `mobile`
- `nominating_member_number`, `nominating_member_name`
- `signature_text`

## Status Enums
Applications:
- `pending_preapproval`
- `rejected_preapproval`
- `pending_board_approval`
- `approved`
- `rejected_board`
- `payment_pending`
- `paid_active` (optional)

Votes:
- `approved`
- `rejected`
- `unanswered`

Subscriptions:
- `pending_payment`
- `active`
- `overdue`
- `lapsed`

Members:
- `pending_payment`
- `active`
- `lapsed`

## Settings
Store in WP options (single option array recommended).
Key settings:
- approval_threshold (default 7)
- rejection_threshold (default 7)
- grace_period_days (default 30)
- prorata_cutoff_days (default 15)
- membership_type_prices (Associate 145, Corporate 145, Senior 70)
- protected_storage_dir (default `/wp-content/protected-folder/iei-membership/`)
- allowed_mime_types (pdf, doc, docx, jpg, jpeg, png)
- bank_transfer_enabled (true)
- bank_transfer_instructions (text)
- active_gateway (`stripe` or `paypal`) (placeholder; not fully implemented in v1)
- director_dashboard_page_id (frontend page for director dashboard)
- member_payment_portal_page_id (frontend page for payment portal)
- member_home_page_id (frontend page for active member landing)
- application_thank_you_page_id (frontend page used after successful application submit)
- next_membership_number (numeric counter for next assigned membership number)

Behavior notes:
- `next_membership_number` is treated as the minimum next number; runtime also checks DB max and uses the higher value.
- After assignment, `next_membership_number` is automatically incremented.

## Cron Job
Daily WP-Cron event `iei_daily_maintenance`:
- Transition overdue->lapsed when grace period is exceeded
- Update subscription/member statuses
- Downgrade WP role when lapsed
Must be idempotent.

## Email Triggers (MVP)
Use wp_mail. Templates stored in settings (subject/body).
Events:
- New application -> Stuart
- Board review needed -> directors
- Director reminder (manual trigger) -> only non-responders
- Approved -> applicant (includes login/password set instructions + payment steps)
- Approved -> Stuart (includes applicant name/company/membership type)
- Payment received -> applicant (personalized welcome + member area link)

## Security Requirements
- Nonces on all POST actions
- Capability checks on all admin and director actions
- Sanitize/validate all inputs
- Attachment access strictly permission-gated; never expose storage paths publicly
- Avoid PII in logs (log event types + minimal identifiers)

## Architecture
Keep code clean and separated:
- Controllers (admin/public)
- Services (business logic)
- Repositories (DB access)
- Views (PHP templates)
- Migrations (table creation)

Practical note:
- Current implementation uses Controllers + Services + Migrations with direct `$wpdb` access in controllers/services.
- Repositories/Models/Views folders are not required for current codebase behavior.

## Suggested structure:
iei-membership/
iei-membership.php
agent.md
/app
/Controllers
/Services
/Repositories
/Models
/Views
/Migrations
/assets


## Build Order (High-Level)
1) Plugin bootstrap + activation hooks
2) DB migrations + table creation
3) Settings page + role/cap setup
4) File storage service + secure streaming controller
5) Public application form (shortcode) + persistence + email to Stuart
6) Admin Applications UI + pre-approve/reject
7) Director management UI (create WP users) + board email + vote row creation
8) Director portal + viewing/voting + threshold auto-finalise
9) Approval finalisation (create WP user pending_payment) + subscription pending_payment + applicant email
10) Payment portal + manual payment marking + activation (membership number + role)
11) Renewal cron (overdue/lapsed + role downgrade)
12) Activity timeline UI + logging everywhere
13) CSV import (active members only)

## Deliverables
- Working plugin with above features
- Minimal developer docs in code + this agent.md
- No excessive documentation

## Important Runtime Notes
- Application thank-you redirect:
   - On successful submit, shortcode redirects to `application_thank_you_page_id` if configured, else current URL.
   - Thank-you template is shown when `?iei_application_submitted=1` is present.
   - If using a separate Thank You page and you want the in-plugin thank-you template, include `[iei_membership_application]` on that page.
- Login redirects:
   - Director users with vote cap -> configured director dashboard page.
   - Pending-payment users -> configured member payment portal page.
   - Active/current members -> configured member home page (fallback `/member-portal/`).

## UX Notes (Current)
- Applications admin list:
   - Status filter uses a select list (clean labels + counts in option text).
   - Status column uses colored chips.
- Application detail:
   - Director vote statuses render as chips (`approved`, `rejected`, `unanswered`).
- Director dashboard voting:
   - Directors cannot change a submitted vote unless admin/stuart resets it.
- Payments admin list:
   - Default view is outstanding statuses (`pending_payment`, `overdue`, `lapsed`).
   - `completed` and `all` views are available via filter.
   - Statuses are rendered as chips.