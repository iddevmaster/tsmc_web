# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

TSMC Web (Transport Safety Manager Communication) is a Laravel 11 application for managing transport-safety
organizations: users/positions with a permission tree, dynamic forms and submissions, vehicle logbooks and
maintenance, work records, and LINE login integration. PHP >= 8.2, Node >= 18, MySQL, server-rendered Blade
+ Bootstrap 5 frontend with a few Livewire components.

## Common commands

Install/setup:
```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed   # creates default admin: username tsmcadmin / password iddrivesadmin
```

Dev servers (run both):
```bash
npm run dev
php artisan serve
```

Build frontend assets:
```bash
npm run build
```

Tests (PHPUnit; note the `tests/` suite is currently just Laravel's example scaffolding, no real coverage yet):
```bash
php artisan test
php artisan test --filter=TestName
vendor/bin/phpunit tests/Feature/SomeTest.php
```

Load testing (k6, hits `/login`):
```bash
k6 run --vus 50 --duration 30s tests/login-test.js
```

Artisan commands specific to this app (in `app/Console/Commands`):
- `php artisan app:resetUsersPassword` — reset all `tsm0...`-prefixed users' password to `tsm` + that week's Monday date (e.g. `23092024`)
- `php artisan app:clear-preview` — clear demo/preview user data
- `php artisan app:set-unexpire {username}` — make a user (or their org, if not a TSM) never expire
- `app:unlink-line {username}` — unlink a user from their LINE user id

## Login gotcha

Auth is by `username`, not `email`. If login doesn't work after a fresh install, edit
`vendor/laravel/ui/auth-backend/AuthenticatesUsers.php` and change the `username()` method to
`return 'username';` (it defaults to `'email'`).

## Architecture

### Multi-tenant org model via "TSM"

- A **TSM** user (`users.is_tsm = true`) is a Transport Safety Manager who can be linked to *multiple*
  organizations through `Tsm_has_Org` (pivot of `tsm_id` <-> `org_id`). Which org a TSM is currently acting
  on is tracked in `session('connected_org')`, set via the `tsm.org.connect` route.
- A **normal (org) user** belongs to exactly one org via `userDetail->org` (see `User_detail`).
- Because of this, almost every controller branches on `Auth::user()->is_tsm` to decide whether to scope
  queries by `session('connected_org')` or by `Auth::user()->userDetail->org`. When adding a new
  org-scoped feature, follow this same branch — don't assume `userDetail->org` is always the tenant id.

### Permissions: Position tree, not Laravel Gates/Policies

- Positions (`app/Models/Position.php`) form a tree via `kalnoy/nestedset` (`NodeTrait`), scoped per org.
- Permissions are granted to positions (not users directly) through the `Position_has_permission` pivot
  (`position_has_permissions` table), keyed by `permission_id` + `org` + `status`.
- Check access in controllers/views with `$position->hasPermissionName('perm_name', $orgId)`, e.g.
  `Auth::user()->userDetail->getPosition->hasPermissionName('can_post', Auth::user()->userDetail->org)`.
  There is no middleware-based authorization (`bootstrap/app.php` registers no custom middleware) — access
  control is done ad hoc in each controller/view via these `hasPermissionName`/`hasThisForm` calls.
- Similarly, which forms a position can fill out is governed by `PositionHasForm` /
  `Position::hasThisForm($formId)`.
- Posts (the internal feed/bulletin feature) have their own parallel permission pivots:
  `Post_has_Permission` / `Post_permission`.

### Dynamic form builder + submissions

- `Form` (with `Form_category`) defines a form; `FormField` (+ `FieldOption` for choice fields) defines its
  fields, ordered by `order_number`. A form can be a "sub-form" (`is_sub_form`), embedded inside a parent
  form and rendered via the `subformfields` accessor on `Form`.
- Filling out a form creates a `FormSubmissions` row, with per-field answers in `FormSubmissionValue` and an
  audit trail in `FormSubmissionHistory`. `FormController` manages form *definitions* (CRUD, permissions);
  `DocumentController` manages *filling out / viewing submissions* of those forms; `ExcelController` and
  `ImportDataController` handle Excel export/import (including bulk user import — see `form_examples/` for
  sample import spreadsheets) and performance reporting.
- Reporting helpers like `Form::countFromSubmissionByQuarter()` re-derive the same TSM/org scoping logic
  described above — keep that consistent when adding new report queries.

### Vehicles, logbooks, work records

- `Vehicle` + `VehicleAssignment` track fleet vehicles and which driver/user is assigned to them.
- `LogBook`/`LogBookEntry` track vehicle usage logs; `LogBookKmSchedule`/`LogBookMonthSchedule` drive
  maintenance scheduling; `RepairHistory`/`PartUse`/`MaCategory`/`MaItem` track maintenance/repair records
  ("car MA" = car maintenance, see `LogBookController`'s `car.ma.*` routes).
- `WorkRecord` + `GeolocationRecord` track field work sessions with a geolocation map view
  (`work-records.geomap`).

### Auth extras

- LINE login is a secondary auth path: `LineController` (`line.login`, `line.check.user`, `line.auth`
  routes, all `withoutMiddleware(['auth'])`) links a `users.line_user_id` to a LINE account.
- `RenewalCode`/`RenewalCodeUsage` implement redeemable codes that extend a user's or org's expiration
  (`expire_at`), redeemed via `RenewalCodeController`.
- `LoginHistory` records every login (see recent commits around login history import/printing/org name
  columns) and backs the `/login-history` and `/login-history/all` (+ Excel export) admin views.

### Routing conventions

- All authenticated routes live under a single `Route::middleware(['auth'])` group in `routes/web.php` —
  there's no route-group-per-feature; look here first when tracing a URL to its controller.
- Most resources use `Route::resource(...)` plus extra explicit routes for non-CRUD actions (e.g.
  `positions.update.post`, `vehicle.assignment.table`). Follow this pattern (resource + bolt-on routes)
  rather than introducing new full custom route sets for CRUD-like features.

### Localization

- Default locale is Thai (`APP_LOCALE=th`), fallback English. UI copy, flash messages, and validation
  errors in controllers are largely written in Thai — match this when adding user-facing strings.
- `phattarachai/thaidate` is used for Thai Buddhist-calendar date formatting.
