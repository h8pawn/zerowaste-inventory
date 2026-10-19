# ZeroWaste: Inventory & Food Waste Management

A web system for a neighborhood grocery store (Lisa Grocery Store) that tracks inventory and expiry dates, records waste and returns, and shows where money is lost. It also runs a public storefront where customers reserve near-expiry deals for pickup, so food gets sold instead of thrown away.

Built with plain PHP and MySQL. There is no framework, no build step and no Composer, so it runs on any ordinary PHP web host.

## Features

**Staff system (sign-in required)**
- **Dashboard:** stock value, expiring items, low stock, open orders, deliveries, and a monthly **Cost vs Waste vs Loss** comparison with charts.
- **Food Items:** add, edit, archive and delete items; filters, sorting, paging and CSV export; suggested expiry dates from a shelf-life estimate.
- **Barcodes:** items without a manufacturer barcode get a store EAN-13 code automatically (prefix `20`). **Quick Scan** adds stock one scan at a time with a USB scanner, and **printable labels** come in three sizes.
- **Automatic expiry handling:** expired stock is logged as "Expired" waste and archived without anyone having to do it.
- **Log Waste, Waste History, Reports:** reasons, trends, top wasted items and categories, CSV export.
- **Returns:** customer returns (restock or dispose) and returns to suppliers (with credit recovered).
- **Suppliers & Deliveries:** supplier records, scheduled deliveries tracked Scheduled → On the way → Delivered, paid/unpaid status, and a notification bell.
- **Online Orders:** pickup reservations from the storefront. Stock is held while an order is open and deducted when it is picked up.
- **Users & Store Settings:** sign-up approval, roles, password resets, store hours, markup, pickup rules, payment details.
- **AI Waste Advisor (admins only):** a built-in AI that learns only from the system's own records. It gives a Zero-Waste Score, ranked recommendations to cut waste (what to mark down or donate, what to order less of, storage, supplier and storefront fixes), an expiry forecast of stock that won't sell in time, safe order sizes, and an "Ask the advisor" chat. Nothing is sent to an outside service.

**Public storefront**
- Live catalog straight from inventory, with **Today's deals** (20% off 2 days before best-before, 50% off on the last day).
- Basket → pickup day and time → payment method (cash, card, e-wallet, bank transfer) → reservation code like `LG-7K2M9Q`.
- **Track order** page (code + mobile number); customers can cancel while an order is still new.

## User roles

| Role | Can do |
|------|--------|
| **Staff** | View everything, print barcode labels, export CSV. Cannot change data. |
| **Manager** | Everything staff can, plus items, Quick Scan, stock, waste, returns, orders, suppliers, deliveries, and staff/manager accounts. |
| **Admin** | Everything, plus admin accounts, Store Settings, the AI Waste Advisor and "Reset system data". |

The **first account registered on a fresh database becomes the admin**. Later sign-ups are inactive staff until a manager or admin activates them.

## Requirements

- **PHP 8.1 or newer** with the `mysqli`, `mbstring` and `ctype` extensions (all standard on hosts and XAMPP).
- **MySQL 5.7+ / 8.x** or **MariaDB 10.3+**.
- **Apache** is recommended: the included `.htaccess` files block private files. On Nginx, see [Nginx](#nginx).

## Run it locally (XAMPP)

1. Put the project in `C:\xampp\htdocs\capstone` and start **Apache** and **MySQL** in the XAMPP Control Panel.
2. In phpMyAdmin (<http://localhost/phpmyadmin>), create a database named `zerowaste` (collation `utf8mb4_general_ci`) and **Import** `database/schema.sql`.
3. Copy `config.local.example.php` to `config.local.php` and set:
   ```php
   'APP_ENV' => 'development',
   'DB_HOST' => '127.0.0.1', 'DB_NAME' => 'zerowaste', 'DB_USER' => 'root', 'DB_PASSWORD' => '',
   ```
4. Open <http://localhost/capstone/>. You'll be asked to create the admin account.

The storefront is at `/front_page.php`.

**Sample data (optional):** to try the AI Waste Advisor and the dashboards, create the admin account and then run `C:\xampp\php\php.exe database\demo_data.php` from the project folder. It adds 14 sample products with stock, sales, waste, returns and pickup orders, dated relative to today. It only runs on an empty inventory, and **Users & System → Reset system data** removes it again. Don't load it on the live site.

## Configuration

Settings are read from `config.local.php` first, then from environment variables with the same names, then from XAMPP defaults. **`config.local.php` is git-ignored**, so passwords never reach GitHub.

| Key | Meaning | Default |
|-----|---------|---------|
| `APP_ENV` | `production` shows visitors a friendly error page and logs details; `development` shows full errors. | `production` |
| `DB_HOST` | Database server | `127.0.0.1` |
| `DB_PORT` | Database port | `3306` |
| `DB_NAME` | Database name | `zerowaste` |
| `DB_USER` | Database user | `root` |
| `DB_PASSWORD` | Database password | *(empty)* |

The app's timezone is fixed to **Asia/Manila** (in `config.php`), and MySQL uses the same offset, so "today" means the same thing everywhere.

## Deploy to a web host

GitHub stores the code, but it cannot run PHP (GitHub Pages only serves static sites). You need a PHP host: any shared host with cPanel, hPanel or DirectAdmin works, as does a VPS.

1. **PHP version:** in the host's control panel, set PHP to **8.1 or newer**.
2. **Database:** create a MySQL database and a user with all privileges on it. Note the host name (often `localhost`), database name, user and password.
3. **Schema:** open phpMyAdmin on the host, select the new database and **Import** `database/schema.sql`.
   *To bring over your existing local data instead*, export your local `zerowaste` database from XAMPP's phpMyAdmin (Export → SQL) and import that file. Do this instead of `schema.sql`, not as well.
4. **Upload the files** into `public_html/` (or a subfolder) with the File Manager (upload a ZIP and extract it), FTP, or `git clone`. Upload everything in this repository, `.htaccess` files included.
5. **Config:** in the same folder, copy `config.local.example.php` to `config.local.php` and enter the database details from step 2. Keep `'APP_ENV' => 'production'`.
6. **Create the admin immediately:** open the site and register. The first account becomes the admin, so do this before sharing the link.
7. **HTTPS:** turn on the host's free SSL certificate (Let's Encrypt / AutoSSL). Then uncomment the "force https" block at the bottom of `.htaccess`. Session cookies are marked `Secure` automatically on HTTPS.
8. **Store Settings:** sign in as admin and set the store name, address, hours, pickup rules and payment details.

### After deploying, check

- [ ] `https://your-site/includes/app.php`, `/database/schema.sql` and `/config.local.php` all return **403 Forbidden**.
- [ ] Logging in, adding an item and placing a test reservation all work.
- [ ] You have a backup plan: export the database from phpMyAdmin regularly (it holds customer phone numbers and password hashes, so keep backups private).

### Updating an existing site

Upload the new files over the old ones but **keep `config.local.php`**. Don't re-import `schema.sql`. Older databases are upgraded automatically on the first page load (see `includes/schema.php`).

### Nginx

`.htaccess` is ignored by Nginx. Add the equivalent to the server block:

```nginx
location ~ ^/(includes|database)/ { deny all; }
location ~ /\.|\.(sql|md|log)$|config\.local(\.example)?\.php$ { deny all; }
```

## Project structure

```
├── index.php               Staff sign-in / first admin sign-up
├── login_register.php      Sign-in and sign-up handler
├── logout.php
├── home_page.php           Dashboard
├── food_items.php          Inventory, Quick Scan, barcodes, CSV export
├── barcode_labels.php      Printable barcode labels
├── archive.php             Archived items (restore / delete)
├── log_waste.php           Record waste
├── waste_history.php       Waste log with filters and CSV export
├── reports.php             Waste analytics
├── returns.php             Customer and supplier returns
├── orders.php              Online pickup orders (staff)
├── suppliers.php           Supplier records
├── deliveries.php          Supplier deliveries
├── admin_panel.php         Users & system
├── ai_advisor.php          AI Waste Advisor (admin)
├── store_settings.php      Storefront and pickup settings (admin)
├── front_page.php          Public storefront
├── store_api.php           Public endpoint for placing reservations
├── order_status.php        Public order tracking
├── config.php              Loads settings and connects to the database
├── config.local.example.php  Template for config.local.php (git-ignored)
├── includes/
│   ├── app.php             Bootstrap: session, security headers, helpers, auth, CSRF
│   ├── schema.php          Automatic upgrades for older databases
│   ├── layout.php          Page chrome, navigation, charts, status helpers
│   ├── store.php           Storefront: settings, pricing, catalog, pickup slots, orders
│   ├── advisor.php         AI Waste Advisor: sales pace, expiry forecast, order sizes, recommendations, score
│   ├── advisor_chat.php    "Ask the advisor" answers
│   ├── deliveries.php      Delivery statuses and notifications
│   ├── barcode.php         EAN-13 / Code 128 generation and SVG rendering
│   └── shelf_life.php      Expiry-date estimation
├── database/schema.sql     Complete database schema and default data
├── database/demo_data.php  Optional sample data for trying the system (command line only)
├── docs/USER_GUIDE.md      Guide for store staff
├── style.css, app.js       Staff interface (light and dark themes)
└── store.css, store.js     Storefront interface
```

## Security

- Passwords are hashed with `password_hash()`; all SQL uses prepared statements; all output is HTML-escaped.
- Every form and AJAX request carries a CSRF token. Session cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` on HTTPS. The session ID changes at sign-in.
- Roles are re-checked against the database on every request, so a deactivated account is signed out at once.
- Staff (view-only) accounts are blocked from changing data on the server, not just in the interface.
- The public reservation form has a honeypot field and limits: 3 reservations per device per 15 minutes, 3 open reservations per mobile number.
- `.htaccess` blocks `includes/`, `database/`, SQL and Markdown files, local config and dotfiles.

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| "The system is temporarily unavailable" | Database connection failed. Check the `DB_*` values in `config.local.php`. Set `APP_ENV` to `development` briefly to see the exact error. |
| "The database is not set up yet" | Import `database/schema.sql` into the configured database. |
| "Something went wrong" | Details are in the host's PHP error log (cPanel → Errors / Logs). |
| 500 error right after upload | The host is running PHP older than 8.1. Change the PHP version in the control panel. |
| "Your session expired. Please try again." | The page was open for a long time. Reload it and resubmit. |
| Barcode labels won't scan | Print at 100% scale ("Actual size"), not "Fit to page". |
