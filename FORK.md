# FoodCoopShop fork "lieblingsspeis"

This fork is based on [FoodCoopShop v4.3.0](https://github.com/foodcoopshop/foodcoopshop) and adds a REST API,
a few settings for shops that bill outside of FoodCoopShop, and some hardening. Everything lives on the branch
`lieblingsspeis`. The other branches are working branches (`fix/*`, `feature/*`) or a mirror of the original (`upstream-develop`).

Nothing here has been sent to the original project. Single features can be offered as pull requests later.

* [What is different from the original](#what-is-different-from-the-original)
* [REST API](#rest-api)
* [Settings](#settings)
* [Cronjobs](#cronjobs)
* [Installation and update](#installation-and-update)
* [Tests](#tests)

## What is different from the original

| Area | Change |
|---|---|
| REST API `/api/v1` | Read and change products (with variants), manufacturers and customers, read orders, place instant orders and move pickup days, read members' contact data. Bearer tokens, per-user permissions. |
| Permissions | New table `fcs_customer_permissions`: rights are granted per user in addition to the customer group. No new user group. |
| API tokens | Tokens are stored as hash and shown only once. They expire after 12 months by default ("no expiry" must be chosen explicitly). One token per app, each can be revoked on its own. Creating and revoking is written to the action log. |
| Address book | Members' contact data as JSON, vCard and read-only CardDAV (for the phone address book). Which members are included is a setting. |
| Billing without invoices | New setting: the invoice cronjob only marks old orders as billed, without PDFs, invoice numbers or e-mails. |
| Pickup day exceptions | Taken over from the unreleased 4.4 development of the original (move single pickup days, e.g. Christmas). |
| MariaDB | Migrations choose the collation per server, so the whole upgrade chain 3.5 → 4.3 also runs on MariaDB 10.11. |
| Hardening | No CORS headers on the API, every API route checks its permission (covered by a test), invalid dates give 422 instead of 500, upload hardening from the original (`harden pdf uploads`, `harden image uploads`, `terms of use upload fix`). |

## REST API

Base URL: `https://<your-shop>/api/v1`. Answers are JSON. Errors look like `{"error": "...", "details": {...}}`.

### Authentication

Send the token as bearer token: `Authorization: Bearer <token>`. `GET /api/v1/me` returns the user, the permissions and
`token_expires`.

Tokens and permissions are managed by a super admin in the admin area under **Homepage administration → API and permissions**:

1. Create (or pick) a user. A technical user ("API user") cannot sign in to the shop, does not order and does not appear in lists or invoices.
2. Tick the permissions the app needs (table below). Admins do **not** get any API permission automatically.
3. Create one token per app. Copy it right away, it is not shown again. The default expiry is 12 months; "no expiry" is possible.
4. To lock out an app, revoke its token. The other tokens keep working.

Use HTTPS only.

### Permissions

| Permission | Allows |
|---|---|
| `api.products.read` / `api.products.write` | read / change products and variants |
| `api.manufacturers.read` / `api.manufacturers.write` | read / create / change manufacturers |
| `api.customers.read` / `api.customers.write` | read / change customers (never passwords, groups or consents) |
| `api.orders.read` | read order items |
| `api.orders.write` | place instant orders for a member and move pickup days |
| `contacts.read` | contact data of members (JSON, vCard, CardDAV); can also be given to normal users |

### Endpoints

| Method and path | Permission | Description |
|---|---|---|
| `GET /me` | any token | user, permissions, token expiry |
| `GET /products` | `api.products.read` | list incl. variants; filters `modified_since`, `manufacturer_id`, `active` |
| `GET /products/{id}` | `api.products.read` | one product |
| `PATCH /products/{id}` | `api.products.write` | name, texts, unit, gross price, deposit, active, quantity, always available |
| `PATCH /products/{id}/attributes/{attributeId}` | `api.products.write` | same for a variant |
| `GET /manufacturers`, `GET /manufacturers/{id}` | `api.manufacturers.read` | read |
| `POST /manufacturers`, `PATCH /manufacturers/{id}` | `api.manufacturers.write` | create / change, same rules as the admin form |
| `GET /customers`, `GET /customers/{id}` | `api.customers.read` | read; filters `active`, `modified_since` |
| `PATCH /customers/{id}` | `api.customers.write` | change |
| `GET /orders` | `api.orders.read` | order items; at least one filter is required: `pickup_day_from`, `pickup_day_to`, `product_id`, `customer_id`, `modified_since` |
| `POST /orders` | `api.orders.write` | instant order for a member directly for a pickup day (see below) |
| `PATCH /orders` | `api.orders.write` | move the order of a member to another pickup day (see below) |
| `GET /contacts`, `GET /contacts.vcf` | `contacts.read` | contact data as JSON / vCard 3.0 |
| `/carddav/`, `/.well-known/carddav` | `contacts.read` | read-only CardDAV address book (user name: anything, password: API token) |

Common behavior of all writing calls:

* `?dry_run=1` only checks and returns what would happen. Nothing is saved.
* `if_unmodified_since` answers with **409** when the record was changed meanwhile (no silent overwriting of an admin's change).
* Validation uses the same table methods as the admin area. Every change is written to the action log (with the token name).
* Invalid input gives **422**, a missing permission **403**.

#### Instant order

```
POST /api/v1/orders?dry_run=1
{"customer_id": 12, "pickup_day": "2026-11-27",
 "items": [{"product_id": 346, "attribute_id": 0, "amount": 2, "quantity_in_units": 0.8}]}
```

Uses the same cart code as the admin's instant order (availability, prices, deposit, tax, stock, confirmation e-mail, action log),
but the pickup day is chosen by the caller, so no second step "change pickup day" is needed. The answer contains all order lines with prices.
`pickup_day` must not be in the past. The call is refused (409) while customers select the pickup day themselves.
A real call may carry `expected_total` (the total from the preview); if the total changed meanwhile, the call is refused with 409.

#### Move pickup day

```
PATCH /api/v1/orders?dry_run=1
{"customer_id": 12, "from_pickup_day": "2026-11-20", "pickup_day": "2026-11-27"}
```

Same effects as "change pickup day" in the admin order list. The optional `order_detail_ids` pin exactly the previewed rows.

## Settings

Admin area → **Settings** (super admin).

| Setting | Meaning |
|---|---|
| Bill orders in the cronjob without invoices (`FCS_BILL_ORDERS_WITHOUT_INVOICES`) | **No** (default): invoices are created as before. **Yes**: the cronjob "send invoices to customers" does not create invoices but marks all open order items with a pickup day **before the run day** as billed (cashless payments: status 11, cash: 12). No PDFs, no invoice numbers, no e-mails, no call to a cash register system. The result is written to the action log. Meant for shops that bill in another system. Needs the retail mode (`FCS_SEND_INVOICES_TO_CUSTOMERS`). |
| Address book (`FCS_CONTACTS_MODE`) | Which members appear in the address book (only for users with `contacts.read`): *only members who agreed* (default, checkbox in the member's profile), *all active members except those who objected* or *all active members*. "Active" means an enabled account that is not an API user. With the two last modes the privacy policy text is extended. Every retrieval is logged. |
| Address book: ordered within … months (`FCS_CONTACTS_ORDERED_WITHIN_MONTHS`) | 0 = all active members. Otherwise only members with an order in the last X months (future orders count). Combined with the selection above. |
| Pickup day exceptions (`FCS_PICKUP_DAY_EXCEPTIONS`) | Move single pickup days to an earlier day, with an own order deadline (e.g. Christmas, New Year). The moved pickup day is shown in the catalog and the cart. |

### One-off: mark old orders as billed

If a shop never billed inside FoodCoopShop, all order lines stay "open" and the order list gets slower with every order. After switching on the
setting above, a plain `UPDATE` can mark everything up to a day as billed once (no invoices, no e-mails). Back up the database first and
use a preview (`SELECT`) and a backup table so the change can be reverted.

## Cronjobs

In addition to the cronjobs of the original (see the [documentation](https://foodcoopshop.github.io)):

* **Send invoices to customers**: with the setting "Bill orders in the cronjob without invoices" this is the job that marks old orders as billed. Activate it in the admin area (Cronjobs), e.g. weekly on Saturday morning.
* **Queue worker**: e-mails (also the confirmation of API orders) are sent by the queue, so the worker must run as in the original.

## Installation and update

* Requirements as in the [README](README.md); additionally the PHP extension **`calendar`** (`cal_days_in_month()` is used for monthly invoices to manufacturers).
* Update from 3.5 or 4.x: put the code of branch `lieblingsspeis` on the server, back up the database, run `bin/cake migrations migrate` (and the plugin migrations as in the original update guide), clear the cache.
* MariaDB 10.11 and MySQL 8.0 are both tested. Maintainer's own checklist for the production switch is not part of this repository.

## Tests

PHPUnit tests as in the original (`vendor/bin/phpunit`). New tests: `ApiOrdersWriteTest`, `ApiSecurityTest`, `ApiActionLogTest`, `ApiContactsSettingsTest`,
`BillOrdersWithoutInvoicesCommandTest`, plus the tests of the API controllers. The suite (806 tests) passes except one test that needs the PHP
extension `calendar`, which is missing in some Docker images.
