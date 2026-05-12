# Take-home implementation report

## Executive summary

This take-home started as a small PHP + SQLite document-sharing app with a staff admin page, document creation, share-link generation, and recipient viewing. The assignment intentionally left several product decisions open, so I treated the work like a short real-world product sprint: understand the current flow, identify the highest-risk customer problem, ship a complete vertical slice first, and only then add polish or differentiators.

In roughly three hours of scoped work, I focused on four outcomes:

1. **Scheduled publishing** so staff can prepare documents ahead of time and recipients cannot read them before the publish time.
2. **AI-assisted drafting** so staff can create a first draft faster, with a local no-cost fallback so the demo is not blocked by API quota.
3. **Human-readable share tokens** so generated recipient links are easier to read, type, and communicate than opaque hex strings.
4. **Share by name** so staff can find a published document by title instead of scrolling through the full document list.

The goal was not to build a large framework. The goal was to make minimal, understandable changes that preserve the existing app shape and keep `docker compose up` working from a fresh clone.

## What the original app did

Before changes, the app had a simple flow:

- `seed.php` recreated `db.sqlite` from `schema.sql` every time the app started.
- Staff could create documents in `public/admin.php`.
- Staff could create recipient share links in `public/share.php`.
- Recipients could view documents by token in `public/view.php`.
- `lib/bootstrap.php` owned the database connection, current staff lookup, audit logging, random hex tokens, and HTML escaping.
- `tests/test.php` had a lightweight test runner with one test for seeded share resolution.

That made the repo easy to reason about, so I kept the implementation consistent with the existing style: plain PHP, simple SQLite queries, no new large dependencies, and the existing test pattern.

## Product interpretation and prioritization

### 1. Scheduled publishing came first

I chose scheduled publishing as the first and most important feature because it is a product correctness issue. If staff schedule a document for the future, recipients must not be able to see it early. That is more important than UI polish because early visibility could break trust or compliance expectations for a public-sector tool.

The minimum complete version needed:

- A `publish_at` field on documents.
- A form input when creating a document.
- A recipient-side check before showing document content.
- Audit logging with the selected publish time.
- Tests proving future documents are not visible.

### 2. AI drafting was the differentiator, but needed a safe fallback

The original customer asks did not explicitly require AI drafting, but the role is AI Product Engineer and the assignment asks how AI-assisted work is configured and used. I added AI drafting as a practical differentiator: staff can enter a title and get a first draft for a public-facing document.

A real issue came up during local testing: API quota can be exhausted. To keep the app demoable, I changed the feature so it defaults to **local no-cost draft mode**. This keeps the button useful without requiring an external provider. API mode is still available through environment variables for OpenAI-compatible providers.

That design gives reviewers two options:

- Run the feature immediately with no key and no quota risk.
- Opt into API mode if they want to test a real model.

### 3. Human-readable share tokens were kept small and practical

The README asks for short readable IDs, and it leaves open whether readable IDs replace or complement share tokens. I chose readable **share tokens** because recipient links are the place where readability matters most. A token like `calm-shore-35` is easier to say and recognize than a long hex string.

This choice avoided a larger rewrite of document identity and preserved the existing recipient URL shape:

```text
/view.php?token=calm-shore-35
```

The UI now explicitly labels the generated token so it is clear that the human-readable part is the token itself, not the full URL.

### 4. Share by name was implemented as simple title search

For a small SQLite app, exact/prefix/partial matching is enough and easy to explain. I avoided fuzzy search because it would add complexity without much benefit for a three-hour assignment. Search only returns published documents, which avoids encouraging staff to share future-scheduled documents before they are ready.

## What changed, by area

### Database and migrations

I added a tiny migration system instead of editing `schema.sql` directly for the new column. This matches the assignment requirement that schema changes go through migration files.

Files added or changed:

- `migrations/001_add_publish_at.sql`
- `lib/migrations.php`
- `migrate.php`
- `seed.php`

The migration adds `documents.publish_at`, backfills existing rows to their `created_at`, and creates an index. `seed.php` now loads the original schema and then runs migrations, so fresh Docker startup still works.

Why this approach:

- It is small enough for the repo.
- It is safe to re-run.
- It avoids introducing a full migration framework.
- It keeps the reviewer flow simple: `docker compose up` still starts from a known database state.

### Scheduled publishing

Files changed:

- `lib/bootstrap.php`
- `public/admin.php`
- `public/share.php`
- `public/view.php`
- `tests/test.php`

What was added:

- `normalize_publish_at()` converts the HTML datetime value into a SQLite-friendly timestamp and defaults to now.
- `is_published()` centralizes the visibility check.
- The admin form includes a `datetime-local` input.
- The documents table shows `Published` or `Scheduled` status.
- The recipient view returns a not-yet-available message if the document is still scheduled.
- The share flow does not allow creating new shares for future-dated documents.

Why this design:

- Visibility logic is centralized in a helper instead of duplicated everywhere.
- Existing immediate-publish behavior is preserved by defaulting to now.
- Staff can still prepare future documents without exposing them early.

### AI drafting

Files changed or added:

- `lib/bootstrap.php`
- `public/draft_api.php`
- `public/admin.php`
- `docker-compose.yml`
- `Dockerfile`
- `README.md`
- `tests/test.php`

What was added:

- `ai_draft_mode()` chooses local or API mode.
- `local_draft()` provides a no-cost draft template.
- `ai_draft()` returns draft body plus metadata such as `source` and `model`.
- `public/draft_api.php` exposes a POST-only JSON endpoint.
- The admin page has a **Draft with AI** button and JavaScript `fetch()` call.
- API mode supports `AI_API_KEY`, `OPENAI_API_KEY`, `AI_API_BASE_URL`, `AI_MODEL`, and local fallback for quota/rate-limit responses.
- Audit logs capture AI draft usage with source/model details.

Why this design:

- The app remains usable without API keys.
- The demo is not blocked by quota errors.
- API mode can work with OpenAI or another OpenAI-compatible provider.
- The user must still review the generated text before saving, which is important for public-sector content.

### Human-readable share tokens

Files changed:

- `lib/bootstrap.php`
- `seed.php`
- `public/share.php`
- `tests/test.php`

What was added:

- `slug_token()` creates tokens like `cobalt-river-47`.
- `unique_share_token()` checks the database to avoid collisions.
- New shares use readable slug tokens instead of long hex strings.
- The success banner separately shows the token and the full share URL.

Why this design:

- It improves readability where staff and recipients actually see links.
- It avoids changing document primary keys or rebuilding all routes.
- It keeps token-based recipient access, which is still better than exposing plain numeric document IDs to recipients.

### Share by name

Files changed:

- `public/admin.php`
- `public/share.php`
- `tests/test.php`

What was added:

- A **Find by title** link from the admin document list.
- A search form on the share page.
- Matching by title or ID.
- Ordering that prefers exact title match, then title prefix, then partial matches.
- Filtering so only published documents appear in results.

Why this design:

- It solves the immediate staff workflow problem.
- It is transparent and easy to test.
- It avoids heavier fuzzy-search dependencies.

### Tests

The original test runner was intentionally lightweight, so I expanded it rather than adding PHPUnit. The test suite now covers:

- Seeded share link resolution.
- Human-readable slug token format.
- Share-by-slug document resolution.
- Future-dated document visibility blocking.
- Audit logging for scheduled document creation.
- Share-by-name title search.
- Local no-cost AI drafting without an API key.
- API draft mode validation when no key is configured.

This gives coverage for each shipped feature while staying close to the repo’s original testing style.

## Important tradeoffs and decisions

### I did not build a full authentication system

The original app assumes `current_staff()` returns staff ID 1. I kept that pattern because auth was outside the assignment and changing it would take time away from the customer asks.

### I did not add a heavy migration framework

A full migration tool would be overkill here. The simple numbered SQL file approach is enough for one SQLite database and easy for reviewers to inspect.

### I used local AI fallback instead of requiring a paid model

After hitting quota, it became clear the AI feature needed to be demo-safe. Local mode gives the product experience without external risk. API mode is still there for reviewers who want to test with a provider.

### I used readable share tokens instead of replacing document IDs

Readable document IDs could be useful, but replacing document identity would touch more routes and create more ambiguity around privacy. Human-readable share tokens provide visible customer value with less risk.

### I kept search simple

Exact/prefix/partial search is not perfect, but it is predictable. For this app size and timeline, predictability beats fuzzy magic.

## How to run locally

Start the app with Docker:

```bash
docker compose up --build
```

Open:

```text
http://localhost:8000
```

Run tests from another terminal:

```bash
docker compose exec app php tests/test.php
```

Run migrations manually on an existing database:

```bash
php migrate.php
```

Use local no-cost AI drafting, the default:

```bash
docker compose up --build
```

Use API drafting instead:

```bash
export AI_DRAFT_MODE=api
export AI_API_KEY=your_key_here
# Optional: export AI_API_BASE_URL=https://provider.example/v1/chat/completions
# Optional: export AI_MODEL=provider-model-name
docker compose up --build
```

On Windows PowerShell:

```powershell
$env:AI_DRAFT_MODE="api"
$env:AI_API_KEY="your_key_here"
docker compose up --build
```

## Manual verification checklist

1. Open the admin page and confirm the seeded Welcome Packet appears.
2. Create a document with the default publish time and confirm it shows as published.
3. Generate a share link and confirm the token is readable, for example `calm-shore-35`.
4. Open the generated recipient link and confirm the document body is visible.
5. Create a future-scheduled document and confirm it shows as scheduled.
6. Try to share the scheduled document and confirm the app prevents it until publish time.
7. Use **Find by title** to search for a published document and create a share from the result.
8. Click **Draft with AI** with no API key configured and confirm local draft text appears.
9. Optionally enable API mode and confirm quota/rate-limit errors fall back to local draft mode.
10. Run `docker compose exec app php tests/test.php` and confirm all tests pass.

## What I would improve with more time

- Add edit/reschedule support for existing documents, not just scheduling at creation time.
- Add a real staff login/session model instead of the fixed staff record.
- Add CSRF protection to forms and the draft endpoint.
- Add a share expiration or revoke feature.
- Add a clearer audit log viewer in the admin UI.
- Add browser-level tests for the main flows.
- Add pagination for larger document lists.
- Consider stronger readable-token entropy if the app becomes high volume.

## Video walkthrough outline

For a 5-minute walkthrough, I would cover:

1. The original app flow and where I made changes.
2. Why scheduled publishing was the first priority.
3. The migration approach and why it is intentionally small.
4. A demo of creating a scheduled document and recipient blocking.
5. A demo of local no-cost AI drafting and API fallback reasoning.
6. A demo of human-readable share tokens and share-by-name search.
7. The tests and what I would improve with more time.
