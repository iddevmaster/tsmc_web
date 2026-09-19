# Mandatory Report (รายงานภาคบังคับ) — Design Spec

Addresses the 2026-09-19 customer-feedback item in [customer-feedback.md](../../customer-feedback.md):
customers must file a quarterly mandatory report on the Department of Land Transport's (กรมการขนส่งทางบก)
tsmthai system. The report is a fixed list of items, each asking for a count of times (ครั้ง) and a count
of distinct units (คัน / คน / เส้นทาง / เล่ม / หน่วยงาน). Customers want a page in our system that shows
these numbers, computed from the forms they already fill out, so they can copy them into tsmthai by hand.

## Goal and boundaries

A single read-only page, **Mandatory report**, that shows the tsmthai item list for a selected quarter of
the current year, for the org the user is currently acting on, with each item's two numbers computed from
form submissions according to **report rules** attached to forms.

The feature deliberately does not:

- submit anything to tsmthai — the user copies numbers manually;
- export to Excel/PDF or offer drill-down into the underlying submissions;
- include a "หมายเหตุ" (remarks) column;
- require every item to be covered — items with no rule show "–";
- filter by submission status (approved or not) — all submissions count, same as `/submission-count`;
- offer a year selector in the UI (the service accepts a year for future use);
- back-fill rules onto forms that were cloned before this feature existed (the system does not record
  clone provenance; orgs set rules on those forms themselves).

## Terminology

- **Item**: one line of the tsmthai mandatory report (e.g. "5. การตรวจวัดระดับแอลกอฮอล์ของผู้ประจำรถ"),
  identified by a stable `item_code`. Items belong to **sections** (e.g. "การจัดการผู้ประจำรถ").
- **Report rule**: a row in `form_report_rules` saying "submissions of form F count toward item I, when
  condition C holds, with distinct units taken from D".
- **Times (ครั้ง)**: number of distinct matching submissions for an item in the period.
- **Units (คัน/คน/…)**: number of distinct non-null unit keys across those same submissions.

## 1. Item catalog — `config/mandatory_report.php`

The item list is defined by the Department of Land Transport and changes rarely, so it lives in code.
Labels and units are copied verbatim from the tsmthai form.

```php
return [
    'sections' => [
        'vehicle' => [
            'label' => 'การจัดการตัวรถ',
            'items' => [
                'vehicle_maintenance_plan'      => ['label' => '1. การจัดทำแผนบำรุงรักษารถ', 'unit' => 'คัน'],
                'vehicle_readiness_check'       => ['label' => '2. การตรวจความพร้อมของรถและอุปกรณ์', 'unit' => 'คัน'],
                'vehicle_safety_equipment_check'=> ['label' => '3. การตรวจอุปกรณ์และเครื่องมือเครื่องใช้ที่จำเป็นที่เกี่ยวข้องกับความปลอดภัย', 'unit' => 'คัน'],
            ],
        ],
        'crew' => [
            'label' => 'การจัดการ ผู้ประจำรถ',
            'items' => [
                'crew_duty_assignment'   => ['label' => '1. การกำหนดหน้าที่และความรับผิดชอบของผู้ประจำรถ', 'unit' => 'คน'],
                'crew_driver_work_plan'  => ['label' => '2. การจัดทำแผนการทำงานของผู้ขับรถ', 'unit' => 'คน'],
                'crew_training_plan'     => ['label' => '3. การจัดทำแผนการอบรมผู้ประจำรถ', 'unit' => 'คน'],
                'crew_health_check_plan' => ['label' => '4. การจัดทำแผนการตรวจสุขภาพผู้ประจำรถ', 'unit' => 'คน'],
                'crew_alcohol_test'      => ['label' => '5. การตรวจวัดระดับแอลกอฮอล์ของผู้ประจำรถ', 'unit' => 'คน'],
                'crew_drug_test'         => ['label' => '6. การสุ่มตรวจสารเสพติดในร่างกายของผู้ประจำรถ', 'unit' => 'คน'],
                'crew_fitness_check'     => ['label' => '7. การตรวจความพร้อมด้านร่างกายและจิตใจของผู้ขับรถก่อนออกเดินทาง', 'unit' => 'คน'],
            ],
        ],
        'operation' => [
            'label' => 'การจัดการ การเดินรถ',
            'items' => [
                'operation_trip_plan'             => ['label' => '1. การจัดทำแผนการเดินทาง', 'unit' => 'เส้นทาง'],
                'operation_speed_control'         => ['label' => '2. การตรวจสอบและจัดการการใช้ความเร็วของรถ', 'unit' => 'คัน'],
                'operation_route_situation_check' => ['label' => '3. การตรวจสอบสถานการณ์การเดินทาง', 'unit' => 'เส้นทาง'],
                'operation_transport_data_record' => ['label' => '4. การจัดเก็บข้อมูลการดำเนินการขนส่ง', 'unit' => ''],
            ],
        ],
        'loading' => [
            'label' => 'การจัดการการบรรทุก และการโดยสาร',
            'items' => [
                'loading_operation_manual' => ['label' => '1. การจัดทำคู่มือการปฏิบัติงาน', 'unit' => 'เล่ม'],
                'loading_safety_check'     => ['label' => '2. การตรวจสอบความปลอดภัยในการบรรทุกคนโดยสาร และการบรรทุกสัตว์หรือสิ่งของ', 'unit' => 'คัน'],
            ],
        ],
        'control' => [
            'label' => 'การควบคุม กำกับดูแล',
            'items' => [
                'control_emergency_plan'         => ['label' => '1. การจัดทำแผนรับมือกรณีเกิดอุบัติเหตุหรือเหตุฉุกเฉิน', 'unit' => 'เล่ม'],
                'control_emergency_coordination' => ['label' => '2. การบริหารจัดการและติดต่อประสานงานกรณีเกิดเหตุฉุกเฉิน', 'unit' => 'หน่วยงาน'],
                'control_accident_report'        => ['label' => '3. การจัดทำรายงานอุบัติเหตุ วิเคราะห์ข้อมูลอุบัติเหตุ วิเคราะห์และประเมินผลการจัดการความปลอดภัยในการขนส่ง', 'unit' => 'เล่ม'],
            ],
        ],
    ],
];
```

19 items across 5 sections. `item_code` keys are stable identifiers stored in the DB; labels may be
edited freely.

## 2. Data model — `form_report_rules`

New migration + model `FormReportRule`.

| Column | Type | Meaning |
|---|---|---|
| `id` | bigint PK | |
| `form_id` | FK → `forms.id`, cascade on delete | Source form |
| `item_code` | string(64) | Must be a key present in the config catalog |
| `condition_field_id` | FK → `form_fields.id`, nullable | Field used to filter submissions; null = every submission counts |
| `condition_values` | json, nullable | Values that mean "done". Null/empty with a field set = field must be non-empty |
| `distinct_by` | enum `user`, `vehicle`, `field`, `none` | What the Units column counts |
| `distinct_field_id` | FK → `form_fields.id`, nullable | Required iff `distinct_by = field` |
| timestamps | | |

- Unique index on `(form_id, item_code)`: a form contributes to an item through at most one rule.
- One form may have many rules (e.g. ROLL CALL → alcohol, drug, fitness items).
- One item may be fed by many forms (e.g. readiness check for buses + for trucks); results are combined.
- Relations: `Form::reportRules()` hasMany; `FormReportRule::form()`, `conditionField()`,
  `distinctField()` belongsTo (with `withTrashed()` on fields so deletions are detectable).

Only root-form fields of type `select`, `autocomplete`, `text`, `number` and `date` may be used as the
condition field or distinct field (subform fields are out of scope).

## 3. Rule settings page

Follows the existing form-chain settings page pattern (`form.chain.*`, `formChain.blade.php`).

Routes (inside the `auth` group in `routes/web.php`), handled by a new `FormReportRuleController`:

- `GET    /forms/{form_id}/report-rules`          → `form.report-rules.edit`
- `POST   /forms/{form_id}/report-rules`          → `form.report-rules.store`
- `PUT    /forms/{form_id}/report-rules/{rule}`   → `form.report-rules.update`
- `DELETE /forms/{form_id}/report-rules/{rule}`   → `form.report-rules.destroy`

Access: same guard as `formChainEdit` — a user may edit rules on forms of their own org; rules on
`is_default` forms may only be edited by `tsmcadmin`. `{rule}` must belong to `{form_id}` (404 otherwise).

Entry point: a "รายงานภาคบังคับ" button in the form management table next to the chain button.

Page contents — a table of the form's rules, each row:

1. **รายการ** — dropdown of items grouped by section (from config).
2. **นับเมื่อ** — "ทุกใบ" or a field of this form. When a `select`/`autocomplete` field is chosen, its
   options appear as checkboxes to pick which values count as "done" (e.g. tick "ตรวจ", not "ไม่ได้ตรวจ").
   For other field types, the rule counts when the field is non-empty.
3. **นับไม่ซ้ำตาม** — คน (only if the form has `select_user`), รถ (only if `select_vehicle`), a field of
   this form, or ไม่นับ.
4. Delete button.

Validation (Thai messages): `item_code` exists in config; not already used on this form
("รายการนี้ถูกผูกกับฟอร์มนี้แล้ว"); referenced fields belong to this form and have an allowed type;
`distinct_field_id` present iff `distinct_by = field`; `user`/`vehicle` only when the form enables it.

Rules whose `condition_field_id` or `distinct_field_id` points to a soft-deleted field show a warning
"ช่องที่ใช้ถูกลบแล้ว" on this page.

## 4. Clone carries rules — `FormController::duplicate`

While copying fields, record `old_field_id → new_field_id`. After fields are copied, copy every
`form_report_rules` row of the original form to the new form, remapping `condition_field_id` and
`distinct_field_id` through that map. So when an org clones a standard form that tsmcadmin has set rules
on, the clone reports correctly with no setup.

No seeder: standard form ids differ between environments. tsmcadmin sets rules on the standard forms once
through the UI.

## 5. Counting — `app/Services/MandatoryReportService.php`

```php
public function build(string $orgId, int $quarter, int $year): array
```

Returns the catalog structure with numbers filled in:

```php
[section_key => [
    'label' => '…',
    'items' => [item_code => [
        'label' => '…', 'unit' => '…',
        'times' => ?int,   // null = no rule covers this item
        'units' => ?int,   // null = no rule counts units
        'sources' => ['ชื่อฟอร์ม', …],
    ]],
]]
```

The controller resolves `$orgId` with the usual TSM branch (`session('connected_org')` for TSM users,
`userDetail->org` otherwise); the service does not touch `Auth`.

Algorithm:

1. Load all rules whose form is visible to the org — `forms.org = $orgId OR forms.is_default`, form not
   soft-deleted, not `is_sub_form` — eager-loading form title and the referenced fields (with trashed).
   Ignore rules whose `item_code` is not in the config.
2. For each rule, query `form_submissions` where `form_id = rule.form_id`, `org = $orgId`, and
   `created_at` within the quarter (quarter `q` of `$year`: start of month `3q-2` to end of month `3q`).
   - If `condition_field_id` is set and that field is soft-deleted → rule contributes nothing.
   - If `condition_field_id` is set → `whereExists` a `form_submission_values` row for that field with a
     non-empty `value`, and additionally `value IN condition_values` when `condition_values` is non-empty.
   - If `distinct_by = field` and that field is soft-deleted → rule still contributes to Times, not Units.
3. Select `(submission id, unit key)` per matching submission, where unit key is `user_id`, `vehicle_id`,
   the trimmed value of `distinct_field_id` for that submission, or null for `none`.
4. Aggregate per `item_code` across its rules:
   - `times` = count of distinct submission ids;
   - `units` = count of distinct non-null, non-empty unit keys; `null` if every rule of the item is
     `distinct_by = none`;
   - `sources` = titles of the contributing forms.
5. Items with no rule → `times = null`, `units = null`.

Query count is one per rule (tens per org), each filtered by `form_id` + `org` + `created_at`. Verify an
index covering `form_submissions (form_id, org)` exists during implementation; add one in the migration if
not. No caching.

Known consequence of the definition: forms are filled one submission per vehicle/person, so an item such
as "แผนบำรุงรักษารถ" shows e.g. "12 ครั้ง / 12 คัน" where the customer might file "1 ครั้ง / 12 คัน". This
is accepted — the page shows what was recorded; the customer adjusts when filing.

## 6. Report page

- Route `GET /mandatory-report?quarter=N` → `mandatory.report`, handled by `MandatoryReportController@index`;
  `quarter` defaults to the current quarter and is validated to 1–4.
- View `resources/views/exportDocument/mandatoryReport.blade.php`, same layout/style as
  `submissionCount.blade.php`: quarter dropdown that reloads with `?quarter=`.
- Table header: `(รายการ) | จำนวน | หน่วย | จำนวน | หน่วย` → Times, "ครั้ง", Units, item unit. Section rows
  as bold full-width rows; item rows indented. Under each item label, small muted text
  "จาก: <form title>, …" when sources exist. `null` renders as "–".
- Menu link "รายงานภาคบังคับ" in `layouts/app.blade.php` next to the existing `submission.count` link, in
  both places it appears (desktop and mobile menus).
- Access: same condition as the `submission.count` page/menu link.

## 7. Testing — `tests/Feature/MandatoryReportTest.php`

Following `FormChainLinkingTest` conventions:

1. Rule with no condition, `distinct_by = user` → Times = submission count, Units = distinct users.
2. Select-field condition: only submissions whose value is in `condition_values` count.
3. Two forms feeding one item → Times summed; same user across both forms counted once in Units.
4. Submissions outside the quarter or from another org are excluded.
5. `distinct_by = field` on a text field (route) → distinct trimmed values.
6. Cloning a form copies its rules with field ids remapped to the clone's fields.
7. Condition field soft-deleted → that rule contributes 0, not "all submissions".
8. Item with no rule → `times` and `units` are `null`.
9. Rule settings validation: duplicate `item_code` on the same form is rejected; a field from another form
   is rejected.
