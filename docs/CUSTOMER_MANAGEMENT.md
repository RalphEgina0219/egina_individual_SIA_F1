# ShopLink Customer Management Module

Implementation reference for the Customer Management Module of the ShopLink Supermarket Corporation management system (Laravel 12 API + React frontend). The module implements the six business processes in the AS-IS Business Process Architecture. The AS-IS documentation describes Bagisto v2.x as the current platform; this project keeps the existing Laravel + React architecture.

## 1. Business processes

| # | AS-IS process | Where in the app | Main API |
|---|---------------|------------------|----------|
| 1 | Customer Registration and Account Creation | Customer Registration, All Customers, Customer Profile | `POST /api/customers`, `/verify`, `/activate`, `/status` |
| 2 | Loyalty Membership and Points Management | Loyalty Membership, Loyalty Points, Profile → Loyalty Points | `/api/loyalty/*`, `/api/customers/{id}/loyalty/*` |
| 3 | Customer Purchase History Management | Purchase History, Profile → Purchase History, Orders | `/api/purchase-history/*`, `/api/customers/{id}/purchases` |
| 4 | Customer Feedback and Complaint Management | Feedback Management, Complaint Tracking | `/api/feedback/*` |
| 5 | Loyalty Reward Redemption | Reward Redemption | `/api/rewards/*`, `/api/redemptions/*` |
| 6 | Customer Account Update and Maintenance | Customer Profile (Edit, Status, Account History) | `PUT /api/customers/{id}`, `/account-logs` |

### Workflow notes

**Registration.** Staff enter the customer details and the system checks for duplicates. A matching phone number (normalized, so `0917…` equals `+63 917…`) or e-mail always blocks the registration. A matching name, or name plus birthday, requires staff confirmation. Staff can record identity verification, activate the account, and enroll the customer in the loyalty program in the same step. The **Customer ID** (`CUST-000123`) and **Membership Number** (`SLM-2026-000123`) are generated from the record ID, so they are always unique. They never change on later updates.

**Statuses.** Customer statuses are Pending Verification, Verified, Active, VIP, Inactive, Suspended, Blocked and Archived. Activation requires verification first. Setting a customer to Inactive, Suspended, Blocked or Archived requires a reason. Only Verified, Active and VIP accounts can earn or redeem points. Blocked and Archived accounts cannot be attached to sales.

**Membership levels.** New accounts start at Regular and move to Registered once verified or activated. Enrolling in the loyalty program makes the customer a Loyalty Member. Gold Member and VIP are assigned in two ways:
- automatically, when an administrator sets a lifetime-points threshold for the level;
- manually, from the customer profile. A manually assigned level can be locked so automatic upgrades don't change it.

Levels are never lowered automatically.

**Points earning.** Points are earned when an order associated with an enrolled customer reaches **Delivered**, using this formula:

`points = floor(order total ÷ amount per point × level multiplier)`

The "amount per point", the minimum purchase and the level multipliers are set by an Admin or Manager. The system ships with earning **disabled** and **no rate**; no conversion rate is assumed. Cancelling the order reverses the points.

Every balance change is an immutable ledger entry (`loyalty_transactions`), written in a database transaction while the member's balance row is locked. Each order and each redemption has a unique reference, so a posting can never be duplicated.

**Points expiry (optional).** If "expire after inactivity (months)" is set, a member's balance expires after that many months without points activity. The `loyalty:expire-points` command runs this daily. It can also be run manually from the settings page.

**Redemption.** Staff retrieve the customer and the system lists rewards with an eligibility verdict. Confirmation re-validates everything on the server inside a transaction:
- account status;
- enrollment;
- reward availability and validity dates;
- stock;
- minimum membership level;
- per-customer limit;
- points balance.

Each request carries an idempotency key, so double-submitting returns the original redemption instead of deducting twice. Managers can cancel a redemption; this refunds the points and restores the stock.

**Feedback and complaints.**
- **Recording:** feedback from every channel (in-store, website, e-mail, phone, social media) is entered by staff in one place. Each record gets a reference number (`FB-2026-000001`).
- **Assignment:** complaints, product concerns and service concerns are categorized and assigned to a department, and optionally to a staff member. Assigned staff can see and respond to their concerns even without feedback permissions.
- **Resolution:** a complaint must be Resolved, with a recorded resolution, before it can be Closed. Only managers can reopen closed items.
- **History:** every status change and response is kept.

**Account updates.** Changing the name, contact number, e-mail or birthday requires a confirmed identity check and re-runs duplicate detection. Every change is stored in `customer_account_logs` with old and new values, the staff member, the verification method and the channel the request came through.

## 2. Pain points: current implementation vs future improvements

| Pain point | What this implementation provides | Still manual / future (TO-BE) |
|------------|-----------------------------------|-------------------------------|
| P1 Manual registration and encoding | Structured registration form with validation | Staff still encode the data (no customer self-service portal) |
| P2 Delayed account verification | Verification and activation can be done during registration; the Pending Verification count is on the dashboard | Identity checks are performed by staff |
| P3 Delayed loyalty points sync | Points post automatically when an order is marked Delivered; "Post Missing Points" for discrepancies | POS hardware integration not included |
| P4 Delayed purchase history sync | Purchase history reads the orders table directly; walk-in sales can be linked afterwards | - |
| P5 Feedback from many channels | One central register with a channel field | **No automatic import** from website, e-mail or social media; staff record them |
| P6 Manual complaint coordination | Department or staff assignment, status tracking, response history, aging indicator | No automatic routing rules or SLA escalation |
| P7 Manual redemption verification | Server-side eligibility and balance checks, transactional deduction | Staff confirm the redemption |
| P8 Updates need staff assistance | Audited, identity-checked updates | Updates are still staff-assisted (no customer self-service) |
| P9 Manual customer reporting | Customer Dashboard and Customer Reports from live data with CSV export | No scheduled report e-mails |
| P10 Manual inactive-customer identification | "No purchase in N days" filter and the inactive-customer report | No automatic status change to Inactive |
| P11 Duplicate customer accounts | Duplicate detection on registration and on contact-detail updates | Merging duplicate records is not implemented |

## 3. Roles and permissions

Enforced by the `permission` middleware (`app/Http/Middleware/PermissionMiddleware.php`) using the map in `app/Support/Permissions.php`. The frontend receives the user's permission list at login (`user.permissions`) and only hides what the backend would refuse.

| Capability | Admin / Manager | Customer Service | Marketing | Sales | Warehouse |
|------------|:---:|:---:|:---:|:---:|:---:|
| Customer dashboard | ✓ | ✓ | ✓ | | |
| View customers | ✓ | ✓ | ✓ (no birthday, address, notes or verification details) | lookup only | |
| Register, verify, update, change status | ✓ | ✓ | | | |
| Delete customer (no history only) | ✓ | | | | |
| Purchase history | ✓ | ✓ | ✓ | | |
| Verify / associate transactions | ✓ | ✓ | | associate only | |
| Loyalty balances and ledger | ✓ | ✓ | ✓ | ✓ | |
| Enroll in loyalty | ✓ | ✓ | | | |
| Adjust points, configure rules and levels | ✓ | | | | |
| Rewards catalogue management | ✓ | view | view | | |
| Process redemptions | ✓ | ✓ | | | |
| Cancel redemptions | ✓ | | | | |
| Record and respond to feedback | ✓ | ✓ | | | |
| Assign complaints | ✓ | | | | |
| Respond to complaints assigned to them | ✓ | ✓ | ✓ | ✓ | ✓ |
| Customer reports, announcements | ✓ | | ✓ | | |
| Users and branches | ✓ | | | | |
| Orders | ✓ | | | ✓ | |
| Inventory and warehouses | ✓ | | | view | ✓ |

## 4. Database

New migrations live in `database/migrations/2026_11_03_*`. Existing data is preserved and backfilled.

| Table | Purpose |
|-------|---------|
| `branches` | ShopLink branches (preferred branch, order branch, feedback branch) |
| `customers` *(extended)* | `customer_code`, `membership_number`, `birthday`, `preferred_branch_id`, `preferred_contact_method`, `customer_type`, `status`, verification and activation fields |
| `membership_levels` | The 5 levels with configurable upgrade thresholds and points multipliers |
| `customer_memberships` | One per customer: level, loyalty enrollment, points balance, lifetime totals |
| `loyalty_settings` | Admin-configurable earning rules (key/value) |
| `loyalty_transactions` | Immutable points ledger with unique references |
| `rewards` | Reward catalogue (soft deletes, so past redemptions keep their reward) |
| `reward_redemptions` | Redemption records with idempotency key and cancellation data |
| `customer_feedback` | Feedback, suggestions, complaints and concerns from all channels |
| `complaint_assignments` | Department / staff assignment history |
| `feedback_responses` | Investigation notes, resolutions, customer communication, status changes |
| `customer_account_logs` | Audit trail of every account change |
| `customer_notifications` | Every notification attempt and its result |
| `users` *(changed)* | `role` widened from an enum to a string; `is_active` added |
| `orders` *(changed)* | `transaction_number`, `branch_id`; `customer_id` is now nullable for walk-in sales |

## 5. API endpoints

All endpoints require `Authorization: Bearer <token>` (Sanctum). Responses use the shape `{ success, message?, data }`. Error responses work as follows:
- **422:** validation errors and business-rule violations;
- **409:** duplicate accounts (returned with the matching records) or duplicate postings;
- **403:** missing permission;
- **404:** unknown records.

**Options and analytics**

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/customer-management/options` | Reference lists (statuses, types, channels, branches, levels, departments) |
| GET | `/api/customer-management/dashboard?start_date&end_date&branch_id` | Dashboard statistics and charts |
| GET | `/api/customer-management/reports?start_date&end_date&branch_id&inactive_days` | Segmentation, top and inactive customers, birthdays, loyalty and feedback summaries |
| POST | `/api/customer-management/announcements` | Promotional e-mail to selected customers or a segment |

**Customers (Modules 1 and 6)**

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/customers` | List: `search, status, customer_type, branch_id, membership_level_id, loyalty, inactive_days, registered_from/to, sort` |
| GET | `/api/customers/lookup?q=` | Quick lookup (point of sale, redemption, feedback) |
| POST | `/api/customers/check-duplicates` | Duplicate check |
| POST | `/api/customers` | Register. Options: `identity_verified, verification_method, activate, enroll_loyalty, confirm_not_duplicate` |
| GET / PUT / DELETE | `/api/customers/{id}` | Profile, update (`identity_verified, verification_method, request_channel, notify_customer`), delete |
| POST | `/api/customers/{id}/verify` | Record identity verification |
| POST | `/api/customers/{id}/activate` | Activate |
| POST | `/api/customers/{id}/status` | Change status (`status, reason`) |
| GET | `/api/customers/{id}/account-logs` | Account history |
| GET | `/api/customers/{id}/notifications` | Notification history |

**Loyalty (Module 2)**

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET / PUT | `/api/loyalty/settings` | Earning rules and levels |
| PUT | `/api/loyalty/levels/{id}` | Level threshold, multiplier, benefits |
| GET | `/api/loyalty/calculate?amount&membership_level_id` | Points preview |
| GET | `/api/loyalty/members` | Members, upgrade progress (`near_upgrade=1`) |
| GET | `/api/loyalty/transactions` | Ledger, with totals by type |
| POST | `/api/loyalty/orders/{order}/sync` | Post missing points for a completed order (idempotent) |
| POST | `/api/loyalty/expire` | Run inactivity expiry |
| GET | `/api/customers/{id}/loyalty` | Member summary and ledger |
| POST | `/api/customers/{id}/loyalty/enroll` | Enroll in the loyalty program |
| POST | `/api/customers/{id}/loyalty/level` | Assign a level manually (`membership_level_id, reason, lock`) |
| POST | `/api/customers/{id}/loyalty/adjust` | Authorized adjustment (`points` ±, `reason`) |

**Purchase history (Module 3)**

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/purchase-history` | All transactions (`search, status, branch_id, start_date, end_date, linked`) |
| GET | `/api/customers/{id}/purchases` | A customer's transactions plus summary |
| GET | `/api/purchase-history/{order}/verify` | Discrepancy checks |
| POST | `/api/purchase-history/{order}/associate-customer` | Link a walk-in sale (`customer_id, reason`) |

**Feedback (Module 4)**

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET / POST | `/api/feedback` | List (`complaints_only, status=open, type, category, channel, priority, department, assigned_to_me`) / record |
| GET / PUT | `/api/feedback/{id}` | Detail / categorize (`type, category, priority`) |
| POST | `/api/feedback/{id}/assign` | Assign (`department, assigned_to, notes`) |
| POST | `/api/feedback/{id}/responses` | Investigation, resolution, customer communication |
| POST | `/api/feedback/{id}/status` | Change status (`status, note, notify_customer`) |

**Rewards and redemption (Module 5)**

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET / POST / PUT / DELETE | `/api/rewards[/{id}]` | Catalogue |
| GET | `/api/customers/{id}/rewards` | Rewards with an eligibility verdict for the customer |
| POST | `/api/redemptions/check` | Eligibility check |
| POST | `/api/redemptions` | Confirm (`customer_id, reward_id, quantity, branch_id, notes, idempotency_key`) |
| GET | `/api/redemptions[/{id}]` | History |
| POST | `/api/redemptions/{id}/cancel` | Cancel and refund (`reason`) |

**Administration**

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET / POST / PUT / DELETE | `/api/users[/{id}]` | Staff users (DELETE deactivates) |
| GET / POST / PUT | `/api/branches[/{id}]` | Branches |
| POST | `/api/forgot-password`, `/api/reset-password` | Staff password reset |

## 6. Notifications

`App\Services\CustomerNotifier` sends customer e-mails through Laravel's mail configuration and records **every** attempt in `customer_notifications` as Sent, Skipped or Failed, with the reason. Customers can see this history on their profile under Notifications.

| Notification | Trigger |
|--------------|---------|
| Account Registration | Customer registered |
| Account Verification | Identity verified |
| Welcome Message | First activation; loyalty enrollment |
| Loyalty Points Earned | Points posted for an order |
| Loyalty Points Redeemed | Redemption confirmed |
| Membership Upgrade | Automatic or manual upgrade |
| Birthday Promotion | `customers:birthday-greetings` (daily at 08:00, once per customer per year); "Greet" in Customer Reports |
| Customer Feedback Received | Feedback recorded for a known customer |
| Complaint Status Update | Customer communication by e-mail, "notify customer" on a status change |
| Promotional Announcement | Customer Reports → Send Announcement |
| Account Status Notification | Status changed (except Archived) |
| Account Update Confirmation | Profile updated with "send confirmation" ticked |
| Password Reset | Staff password reset (Laravel's built-in notification) |

**Setup**
- **Configuration:** set `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` and `MAIL_FROM_ADDRESS` in `.env`. Never commit credentials.
- **Default behaviour:** the default `MAIL_MAILER=log` writes messages to `storage/logs/laravel.log` and records them as Sent; nothing is actually delivered.
- **Disabling:** `SHOPLINK_NOTIFICATIONS_ENABLED=false` records notifications as Skipped.
- **Reset links:** `FRONTEND_URL` is the React URL used in password-reset links.
- **SMS:** not implemented. SMS-preferring customers who have an e-mail address receive e-mail; customers without one are recorded as Skipped.
- **Opt-out:** promotional messages respect a contact preference of "None".
- **Scheduled jobs:** the birthday and expiry commands run when the scheduler is active: `php artisan schedule:work` in development, or a cron entry running `php artisan schedule:run` every minute.

## 7. Running

```bash
composer install
cp .env.example .env && php artisan key:generate   # configure DB_* (MySQL)
php artisan migrate --seed        # fresh install; use `php artisan migrate` to upgrade existing data
php artisan serve                 # API on http://127.0.0.1:8000

cd frontend && npm install && npm run dev   # UI on http://localhost:5173
```

After the first login as `admin@example.com` (on any shared or public server, change the seeded password immediately):
1. **Enable earning:** open Loyalty Membership → Program Rules & Levels, set the purchase amount per point, and enable earning.
2. **Upgrade thresholds (optional):** set lifetime-points thresholds for Gold Member and VIP.
3. **Branches and rewards:** review the sample branches and the sample rewards catalogue.

Tests: `php artisan test` (SQLite in memory).

## 8. Known limitations

- External feedback channels (website forms, e-mail inbox, social media) are not integrated; staff record those items manually.
- SMS delivery is not implemented, and e-mail delivery depends on the mail settings you configure.
- Customers have no self-service portal: registration, updates and redemptions are staff-assisted, matching the AS-IS process.
- Duplicate accounts are detected but cannot be merged.
- Points are earned on orders marked Delivered; there is no POS hardware integration.
- Settings → General, Security, Notifications and Theme tabs are UI-only (pre-existing) and are not saved to the database. User Management in Settings is fully database-backed.
