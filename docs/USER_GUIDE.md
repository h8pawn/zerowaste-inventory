# ZeroWaste User Guide

For the staff, managers and admins of the store.

## Signing in

- Staff sign-in page: the site address (for example `https://yourstore.com/`), or **Log in** at the top right of the storefront.
- New staff: choose **Request an account**. A manager or admin must activate it under **User Accounts** before you can sign in.
- Forgot your password? Ask a manager or admin to reset it from **User Accounts** (⋮ → Reset password).

## What your role can do

| Role | Can do |
|------|--------|
| **Staff** | See every page, print barcode labels, export lists to CSV. Changes are made by a manager or admin. |
| **Manager** | Add and change items, Quick Scan, receive stock, log waste, record returns, handle online orders, manage suppliers and deliveries, approve and add staff and manager accounts. |
| **Admin** | Everything, plus admin accounts, **Store Settings**, the **AI Waste Advisor** and **Reset system data**. |

## Daily routine

1. **Dashboard:** check the alerts at the top (expiring items, online orders, deliveries).
2. **Expiring Soon:** items within 3 days of expiry. They're marked down on the storefront automatically: **20% off** 2 days before, **50% off** on the last day.
3. **Online Orders:** **Mark ready** once packed, then **Picked up** when the customer collects and pays. Stock is deducted at that moment.
4. **Deliveries:** mark a supplier's delivery **On the way** and **Delivered**, then receive the stock on **Food Items**.
5. **Log Waste:** record anything thrown away the same day.

Expired stock is handled automatically: when an item passes its expiration date, the remaining quantity is logged as "Expired" waste and the item moves to the **Archive**.

## Food Items

- **Add Item:** fill in name, category, quantity, unit, purchase price and expiration date. Leave **Barcode** blank and the system creates a store barcode for you. Leave **Selling price** blank to use the store's default markup.
- **Suggested expiry:** after typing the name, the form suggests an expiration date from typical shelf life and from this store's past batches.
- **Row menu (⋮):** Edit item, Receive stock, Log waste, Record return, Print barcode label, Archive, Delete. Items with waste, return or order history are archived instead of deleted, so reports stay accurate.
- **Show on the online store:** untick this to hide an item from the storefront.
- **Export:** downloads the current filtered list as CSV.

### Quick Scan with a barcode scanner

1. Click **Quick Scan** and scan. Each scan adds **1 unit**.
2. An unknown barcode opens **Add Item** with the barcode filled in. Keep **Save as product template** ticked, and future scans of that product can add new batches by themselves.
3. Scanning an item whose batch has expired starts a fresh batch with a new expiry date.

Scanner tips: any USB scanner that acts as a keyboard works with no driver needed. Most send **Enter** after each code, which is what Quick Scan expects. If a code isn't recognized, clean the label and try another angle.

### Barcode labels

Tick items and choose **Print labels**, or use ⋮ → Print barcode label. Pick a size, the number of copies, and whether to show the price and best-before date. **Print at 100% scale** so the barcodes scan reliably.

## Waste, returns and reports

- **Log Waste:** choose the item, quantity and reason. The value is calculated from the purchase price unless you type your own.
- **Returns:**
  - *From a customer, back on the shelf:* stock goes up.
  - *From a customer, disposed:* logged as "Customer Return" waste.
  - *Back to the supplier:* stock goes down; any credit received counts as money recovered.
- **Waste History:** filter by date, reason or text, and export to CSV.
- **Reports:** waste value and units by period, reason and category, and the top wasted items.
- **Dashboard Cost vs Waste vs Loss:** *Cost* is stock received × purchase price. *Waste* is the value of waste entries. *Loss* is waste plus supplier returns that weren't fully credited.

## Online reservations (storefront)

- Customers choose items, a pickup day and time within store hours, and how they'll pay. They receive a code like `LG-7K2M9Q`.
- Reserved units are held so other customers can't buy them. Stock is deducted only when you mark the order **Picked up**.
- If an item runs short, the order shows a warning. Update the stock or cancel the order with a reason.
- Customers can track or cancel a new order at **Track order** using the code and their mobile number.

## Admin: Store Settings

Store name, tagline and announcement bar; address, phone, email and Facebook page; the weekly opening hours and an Open/Closed override; default markup; pickup window and preparation time; e-wallet and bank details shown to customers; and an on/off switch for online reservations.

## Admin: AI Waste Advisor

**Admin → AI Waste Advisor** (or **AI Advisor** on the Dashboard). Only admins can open it. The advisor learns only from this system's records: stock received, sales, waste, returns, suppliers, storage locations and online orders from the last 90 days. It uses no outside service, and nothing leaves the server.

- **Zero-Waste Score (0–100):** waste as a share of stock cost (target 2% or less), stock forecast to expire unsold, the 30-day trend, and how much expired stock was caught before the system had to clear it. 85 or more counts as near zero waste.
- **Recommendations:** ranked most important first. For example: what to mark down or donate this week, which items to order less of, items to keep chilled, suppliers to talk to, expiring items hidden from the storefront, and older batches to sell first. Each one shows why, in numbers, what to do, and a link to the page where you do it.
- **Expiry forecast:** stock that won't sell before its best-before date at its usual pace, with the value at risk and the next step. The steps follow the zero-waste ladder: sell at a markdown, then donate (for example to a community pantry), then compost. The bin is the last resort.
- **Smart order sizes:** the most worth ordering at once, meaning what sells within one shelf life (30 days at most) minus the stock that will still sell.
- **Ask the advisor:** type a question such as "What should I do today?", "Which supplier causes the most waste?" or "How much milk should I order?", or ask about any item by name. It only answers from the store's records.

The advice gets better with good records: correct expiration dates and purchase prices, a storage location and supplier for each item, a specific waste reason for each entry, and waste logged on the day it happens. Click **Re-analyse** after making changes.

## Good habits

- Enter the correct expiration date for every batch.
- Use consistent item names (always "Banana Lakatan", not a mix of spellings). Expiry suggestions learn from past batches with the same name.
- Log waste the day it happens.
- Back up the database regularly (phpMyAdmin → Export).

## Troubleshooting

| Problem | What to do |
|---------|-----------|
| Can't sign in | Check the email and password; ask an admin to confirm your account is active. |
| "Your account is view and print only" | Your role is Staff. Ask a manager to make the change or upgrade your role. |
| "Your session expired" | The page was open too long. Reload and try again. |
| An item is missing from the list | Clear the search and filters, or check the **Archive**. |
| An item isn't on the storefront | It needs a purchase or selling price, **Show on the online store** ticked, stock, and an expiry date that hasn't passed. |
