# Folio take-home technical report

## Executive summary

Folio began as a small PHP + SQLite document-sharing app: staff could create a document, generate a tokenized share link, and recipients could view the document through that link. The take-home asked for thoughtful product work in a short timebox, with intentionally fuzzy requirements around scheduling, readable identifiers, search, and migrations.

I treated the assignment like a three-hour product sprint. The main goal was to ship a complete, reviewable slice instead of a broad rewrite. The final implementation adds scheduled publishing, a small migration system, AI-assisted drafting with a local no-cost fallback, human-readable share tokens, share-by-name search, audit logging, and tests.

## Original problem

The starter app had the core sharing flow, but it missed several customer-facing workflow needs:

- Documents became visible immediately, so staff could not safely prepare content ahead of time.
- Share tokens were opaque hex strings, which are hard to read, type, or communicate.
- Staff had to find documents from a list instead of searching by title.
- Schema changes had no migration path.
- The app had only one lightweight test.

The prompt also asked us to make judgment calls: how readable IDs should work, how scheduling and sharing interact, how migrations should be structured, and how much to build within the time limit.

## Three-hour prioritization

### Hour 1 — Foundation and scheduled publishing

I started with scheduled publishing because it is the highest-risk product requirement. If a future document is visible too early, that can create trust and compliance problems for a public-sector tool.

Work completed in this phase:

- Added a lightweight migration runner.
- Added a `publish_at` field through a migration.
- Added scheduling input to the admin document creation form.
- Added a centralized publish visibility helper.
- Blocked recipient viewing before publish time.
- Added tests around future-dated document visibility.

### Hour 2 — AI-assisted drafting

After the scheduling path was complete, I added an AI drafting workflow because the role is AI Product Engineer and the product benefits from staff productivity improvements.

The first version depended on an API key, but local testing showed quota can block demos. I changed the design so AI drafting defaults to a local no-cost draft mode and only uses an external API when explicitly configured.

Work completed in this phase:

- Added local no-cost draft generation.
- Added optional OpenAI-compatible API mode.
- Added a POST-only draft endpoint.
- Added admin UI and JavaScript to request a draft from the title.
- Added audit logging for AI draft usage.
- Added tests for local mode and API-mode validation.

### Hour 3 — Sharing usability, tests, and documentation

The final phase focused on workflow usability and review readiness.

Work completed in this phase:

- Replaced opaque share tokens with readable slug tokens like `calm-shore-35`.
- Added share-by-name search for published documents.
- Added UI status labels and clearer share success messaging.
- Expanded test coverage.
- Documented the implementation, tradeoffs, and local run flow.

## What was implemented

### 1. Scheduled publishing

Staff can now set a publish date/time when creating a document. If the timestamp is in the future, recipients cannot view the document yet.

Key behavior:

- New documents default to publishing immediately.
- Future documents show as scheduled in the admin table.
- Recipient view checks publish time before showing document content.
- Future-scheduled documents cannot be shared from the share page until they are published.
- `publish_at` is included in audit details for document creation.

Main files:

- `migrations/001_add_publish_at.sql`
- `lib/bootstrap.php`
- `public/admin.php`
- `public/share.php`
- `public/view.php`
- `tests/test.php`

### 2. Lightweight migrations

The assignment required schema changes to go through migrations. I added a small SQL-file migration system instead of bringing in a framework.

Key behavior:

- `lib/migrations.php` applies migration files in order.
- `migrate.php` can run migrations manually.
- `seed.php` loads `schema.sql` and then runs migrations so Docker startup still works from a fresh clone.
- Applied migrations are recorded in a `migrations` table.

Main files:

- `lib/migrations.php`
- `migrate.php`
- `migrations/001_add_publish_at.sql`
- `seed.php`

Why this approach:

- It satisfies the requirement without overengineering.
- It is easy to inspect during review.
- It keeps the original simple Docker workflow intact.

### 3. AI-assisted drafting with local fallback

The admin form includes a **Draft with AI** button. Staff enter a title, click the button, and the app fills the body field with a first draft for review.

Key behavior:

- Default mode is local and no-cost, so no API key is required.
- API mode can be enabled with environment variables.
- API quota or rate-limit errors fall back to local draft mode by default.
- Draft events are audit logged with source/model metadata.
- Staff still review and edit the text before saving the document.

Main files:

- `lib/bootstrap.php`
- `public/draft_api.php`
- `public/admin.php`
- `docker-compose.yml`
- `Dockerfile`
- `tests/test.php`

Why this approach:

- It demonstrates an AI workflow without making the demo fragile.
- It avoids blocking reviewers on API keys, billing, or quota.
- It keeps humans in control of public-sector content.

### 4. Human-readable share tokens

New share links use readable tokens instead of long hex strings.

Example:

```text
http://localhost:8000/view.php?token=calm-shore-35
```

Key behavior:

- `slug_token()` generates adjective-noun-number tokens.
- `unique_share_token()` checks the database to avoid collisions.
- The share success banner shows both the readable token and full URL.
- Seed data also uses readable tokens.

Main files:

- `lib/bootstrap.php`
- `seed.php`
- `public/share.php`
- `tests/test.php`

Why this approach:

- The recipient link is the part users actually copy and communicate.
- It improves usability without changing document primary keys or route structure.
- It keeps recipient access token-based rather than exposing numeric document IDs.

### 5. Share by name

Staff can search for a published document before creating a share link.

Key behavior:

- Search by title or ID.
- Results prefer exact title matches, then prefix matches, then partial matches.
- Only published documents appear in search results.

Main files:

- `public/admin.php`
- `public/share.php`
- `tests/test.php`

Why this approach:

- It solves the customer workflow problem directly.
- It is predictable and easy to test.
- It avoids heavier fuzzy-search dependencies that are not needed for this app size.

### 6. Tests

The original repo used a small custom PHP test runner. I kept that style and expanded it.

Current coverage includes:

- Seeded share link resolution.
- Human-readable token format.
- Share-by-slug document resolution.
- Future-dated document visibility blocking.
- Audit logging for scheduled document creation.
- Share-by-name search.
- Local AI drafting without an API key.
- API draft mode validation when no key is configured.

Run tests with:

```bash
php tests/test.php
```

or inside Docker:

```bash
docker compose exec app php tests/test.php
```

## Technical design notes

### Scheduling model

I chose a single `publish_at` timestamp on `documents`. This is enough for the requested behavior and keeps the data model simple. The helper `is_published()` centralizes the rule so the admin, share, and recipient flows do not each invent their own visibility logic.

### Token model

I kept the existing token-based recipient view and changed the token shape from hex to readable slug. This provides user-facing readability while preserving the privacy benefits of tokenized access.

### AI model integration

I avoided making the app depend on a paid external model for the default path. Local mode is deterministic and reliable for review. API mode remains available through environment variables for OpenAI-compatible providers.

### Search model

I used SQL `LIKE` matching with explicit ordering. That is enough for this data size and easier to explain than fuzzy matching.

### Migration model

I used numbered SQL migrations with a simple tracking table. This is not a full production migration framework, but it is appropriate for a small PHP/SQLite take-home and satisfies the assignment requirement.

## Tradeoffs and intentionally scoped-out work

### Not built in this timebox

- Editing or rescheduling existing documents.
- Staff authentication and sessions.
- CSRF protection.
- Share expiration and revocation.
- Recipient access analytics.
- Admin audit-log viewer.
- Browser-level end-to-end tests.
- Pagination for large document lists.
- Stronger readable-token entropy for high-volume production usage.

### Why these were deferred

These are valuable improvements, but they are not required to prove the requested product behavior. In a three-hour assignment, finishing the end-to-end scheduled publishing path and making the app easy to run/test was more important than starting several partially complete features.

## Local run guide

Start the app:

```bash
docker compose up --build
```

Open:

```text
http://localhost:8000
```

Run in the background:

```bash
docker compose up --build -d
```

View logs:

```bash
docker compose logs -f app
```

Stop the app:

```bash
docker compose down
```

Run tests:

```bash
docker compose exec app php tests/test.php
```

Run migrations manually:

```bash
php migrate.php
```

## AI configuration

### Default local mode

No key is needed:

```bash
docker compose up --build
```

Then enter a title in the admin form and click **Draft with AI**.

### Optional API mode

Mac/Linux:

```bash
export AI_DRAFT_MODE=api
export AI_API_KEY=your_key_here
export AI_MODEL=gpt-4o-mini
export AI_API_BASE_URL=https://api.openai.com/v1/chat/completions
docker compose up --build
```

Windows PowerShell:

```powershell
$env:AI_DRAFT_MODE="api"
$env:AI_API_KEY="your_key_here"
$env:AI_MODEL="gpt-4o-mini"
$env:AI_API_BASE_URL="https://api.openai.com/v1/chat/completions"
docker compose up --build
```

If quota or rate limits are hit, the app falls back to local draft mode by default.

## Manual verification checklist

1. Open `http://localhost:8000/admin.php`.
2. Confirm the seeded Welcome Packet appears.
3. Create a document with the default publish time and confirm it shows as published.
4. Generate a share link and confirm the token is readable, for example `calm-shore-35`.
5. Open the generated recipient link and confirm the document body is visible.
6. Create a document with a future publish time and confirm it shows as scheduled.
7. Try to share the scheduled document and confirm the app prevents it until publish time.
8. Use **Find by title** to search for a published document and create a share from the result.
9. Click **Draft with AI** with no API key configured and confirm local draft text appears.
10. Run the test suite and confirm all tests pass.

## Suggested walkthrough structure

For a short video or interview walkthrough, I would cover:

1. The original app flow.
2. Why scheduled publishing was the first priority.
3. The migration approach.
4. A demo of scheduled publishing and recipient blocking.
5. A demo of local AI drafting and explanation of API fallback.
6. A demo of readable share tokens and title search.
7. Test coverage and what I would improve next.

## Final summary

This implementation keeps the app small while making it more complete. Scheduled publishing protects recipient visibility, readable share tokens improve usability, share-by-name speeds up staff workflows, and AI drafting adds a product differentiator without depending on external quota. The migration runner and expanded tests make the changes easier to review and safer to extend.
