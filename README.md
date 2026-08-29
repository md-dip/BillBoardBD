# BillboardBD

A web platform for renting outdoor billboard advertising space in Dhaka, Bangladesh.
Advertisers find billboards on a map, book dates, pay an advance, and submit their ad
creative; billboard owners manage their inventory and get paid; a platform admin reviews
every booking and settles payouts.

## Tech stack

**Backend** — Laravel 13 (PHP 8.3+), Laravel Sanctum for bearer-token auth, PostgreSQL.
Exposes a REST API under `/api`.

**Frontend** — React 19 + Vite SPA (`frontend/`), React Router 7, Axios, Leaflet /
react-leaflet on OpenStreetMap for the map, Recharts for dashboard charts, lucide-react
icons. Plain CSS co-located per component — no UI framework in the SPA.

> The repo root is a stock Laravel app. The SPA in `frontend/` is a separate Vite project
> that calls the API at `http://127.0.0.1:8000/api`.

## Actors

Four roles, each with its own UI area (`frontend/src/{shared,client,owner,admin}/`):

| Actor | Auth | Can do |
| --- | --- | --- |
| **Visitor** | none | Browse billboards on the map, filter by location / price / type / size, view a billboard's details, read "How it works", register / log in. |
| **Client** (advertiser) | required | Hold a slot (15-min lock), pay the advance through a mock gateway, submit campaign details + creative, track bookings through the pipeline, pay the balance. |
| **Owner** (billboard owner) | required | List and edit their billboards, set pricing, track permit expiry, approve / reject booking requests for their boards, upload proof of posting, view payout history and edit payout details. |
| **Admin** (platform operator) | required | Full billboard CRUD, review + approve / reject bookings, record balance payments, verify proof of posting, set platform settings (commission %, advance %, final-payment window), view revenue / occupancy reports, issue owner payouts. |

## Booking pipeline

A booking moves through five stages (plus `rejected` at any review point):

```
held → pending_admin_review → pending_owner_approval → confirmed
     → paid_in_full → pending_proof_review → active
```

## Getting started

**Prerequisites:** PHP 8.3+ & Composer, Node.js 20+ & npm, PostgreSQL, Git.

**Backend**

```bash
composer install
cp .env.example .env
php artisan key:generate
# set DB_CONNECTION=pgsql and the DB_* credentials in .env
php artisan migrate --seed
php artisan serve            # API on http://127.0.0.1:8000
```

`composer dev` runs the PHP server, queue worker, and log tailer together.

**Frontend**

```bash
cd frontend
npm install
npm run dev                  # SPA on http://localhost:5173
```

**Demo logins** (seeded, password `password` for all):

| Role | Email |
| --- | --- |
| Admin | `admin@test.com` |
| Client | `client@test.com` |
| Owner | `owner@test.com` |

## Repo layout

```
app/  routes/  database/   Laravel backend (API, models, migrations, seeders)
frontend/                  React SPA — shared / client / owner / admin areas
resources/  vite.config.js  stock Laravel/Vite scaffold, unused by the SPA
```
