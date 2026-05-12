# Folio Take-Home — Document Sharing Application

Folio is a small PHP + SQLite document-sharing app for staff-managed documents and recipient share links. This branch turns the original starter app into a more complete product slice : scheduled publishing, easier sharing, human-readable share links, a simple migration path, tests, and an AI-assisted drafting workflow

## What was asked - The Task

A thoughtful implementation in about three hours, not a rushed full rebuild. Customers wanted three main improvements:

1. **Scheduled publishing** — staff should be able to prepare a document now and make it visible to recipients later.
2. **Human-readable IDs / links** — links should be easier for people to read, say out loud, type, or paste into an email.
3. **Share by name** — staff should be able to find a document by title instead of scrolling a list.

The task also required:

- Schema changes through migration files.
- Tests for built features.
- Audit logging for document creation, scheduling, and sharing actions.
- A `docker compose up` flow that still works from a fresh clone.

## What was completed in the three-hour scope

### 1. Scheduled publishing

Staff can now choose a publish date/time when creating a document. If the publish time is in the future, recipients cannot view the document yet. Instead, they see a clear not-yet-available message.

Why this was first:

- It is the highest product-risk feature.
- Accidentally exposing a scheduled public-sector document early could create compliance and trust issues.
- It touches the full app path: database, admin creation, share flow, recipient view, audit logging, and tests.

Implemented in:

- `migrations/001_add_publish_at.sql`
- `lib/bootstrap.php`
- `public/admin.php`
- `public/share.php`
- `public/view.php`
- `tests/test.php`

### 2. AI-assisted drafting with local fallback

The admin form now has a **Draft with AI** button. By default, it uses a local no-cost draft template, so reviewers can demo the workflow with no API key and no quota risk. API mode is still available for OpenAI-compatible providers through environment variables.

Why this was included:

- I wanted to show a practical AI workflow rather than only traditional CRUD changes.
- Staff at small districts often need help starting public-facing notices, packets, or announcements.
- A local fallback keeps the feature reliable during a live review, even if an API key is missing, quota is exhausted, or a provider rate-limits the request.

Implemented in:

- `lib/bootstrap.php`
- `public/draft_api.php`
- `public/admin.php`
- `docker-compose.yml`
- `Dockerfile`
- `tests/test.php`

### 3. Human-readable share tokens

New share links now use readable tokens such as:

```text
calm-shore-35
cobalt-river-47
steel-trail-76
```

The full recipient link still uses the existing token-based view route:

```text
http://localhost:8000/view.php?token=calm-shore-35
```

Why this approach:

- It improves the part of the app staff and recipients actually copy, paste, read, and verify.
- It avoids a larger document-routing rewrite.
- It keeps recipient access token-based instead of exposing plain numeric document IDs.

Implemented in:

- `lib/bootstrap.php`
- `seed.php`
- `public/share.php`
- `tests/test.php`

### 4. Share by name

Staff can now search for published documents by title or ID before creating a share link. Search prefers exact title matches, then title prefixes, then partial matches.

Why this approach:

- It directly solves the customer workflow problem.
- It is simple, predictable, and appropriate for SQLite.
- It avoids adding fuzzy-search dependencies for a small take-home app.

Implemented in:

- `public/admin.php`
- `public/share.php`
- `tests/test.php`

### 5. Lightweight migrations

A small migration runner was added so schema changes do not require editing `schema.sql` directly. `seed.php` still recreates the local database from scratch, then applies migrations, so Docker startup remains simple for reviewers.

Implemented in:

- `lib/migrations.php`
- `migrate.php`
- `migrations/001_add_publish_at.sql`
- `seed.php`

### 6. Expanded tests

The original app used a lightweight PHP script instead of PHPUnit. I kept that style and expanded it to cover each shipped feature.

Current test coverage includes:

- Seeded share resolution.
- Human-readable slug token format.
- Share-by-slug resolution.
- Future-dated document visibility blocking.
- Audit logging for scheduled document creation.
- Share-by-name title search.
- Local no-cost AI drafting.
- API draft mode validation when no key is configured.

Implemented in:

- `tests/test.php`

## Why this order was chosen

### Hour 1 — Foundation and scheduled publishing

I started with migrations and scheduled publishing because that was the most important product correctness path. The goal was to make sure a future-dated document cannot be viewed too early.

Work completed:

- Added migration support.
- Added `publish_at` to documents.
- Added publish date/time input to the admin form.
- Added visibility checks in recipient view.
- Added scheduling-related audit details and tests.

### Hour 2 — AI drafting workflow

After the core product risk was addressed, I added an AI drafting workflow. I kept it behind a small endpoint and made the UI review-first: generated text is inserted into the body field, but staff still decide what to save.

Work completed:

- Added local no-cost draft mode.
- Added optional API mode for OpenAI-compatible providers.
- Added `public/draft_api.php`.
- Added admin UI and JavaScript `fetch()` flow.
- Added audit logging for AI draft events.

### Hour 3 — Human-readable tokens, search, tests, and polish

The final hour focused on improving staff usability and tightening verification.

Work completed:

- Replaced long random share tokens with readable slug tokens.
- Added share-by-title search.
- Added UI labels and status badges.
- Expanded tests.
- Updated documentation and local run instructions.

## Key design decisions

### Readable share tokens instead of replacing document IDs

The prompt allowed interpretation around whether readable IDs replace or complement share tokens. I chose readable share tokens because recipient links are the user-facing artifact. This delivers value quickly and avoids a larger route/database rewrite.

### Local AI fallback by default

A pure external API integration is fragile for a take-home review because it can fail due to missing keys, quota, billing, or rate limits. Local mode keeps the product behavior available without external dependencies, while API mode still demonstrates how the app can integrate with a real provider.

### Simple SQL search instead of fuzzy search

For this app size, exact/prefix/partial matching is enough. It is easy to understand, easy to test, and does not require new dependencies.

### Minimal migration runner instead of a framework

The app is intentionally small. A numbered-SQL-file migration runner gives us the safety the prompt asked for without adding unnecessary complexity.

## How to run locally

### Prerequisites

- Docker Desktop or Docker Engine with Compose.
- No local PHP or SQLite installation is required for normal Docker usage.

### Start the app

```bash
docker compose up --build
```

Open:

```text
http://localhost:8000
```

The app starts at the admin page. On startup, `seed.php` recreates `db.sqlite`, applies migrations, seeds a sample document, and prints a sample share link.

### Run in the background

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

### Run tests

With the container running:

```bash
docker compose exec app php tests/test.php
```

If PHP is installed locally, you can also run:

```bash
php tests/test.php
```

### Run migrations manually

For an existing local database:

```bash
php migrate.php
```

## AI drafting modes

### Default mode: local no-cost drafts

No API key is required. Just run:

```bash
docker compose up --build
```

Then open the admin page, enter a document title, and click **Draft with AI**. The generated draft is local template text that staff can review and edit before saving.

### Optional mode: external API drafting

Use this only if you want to test an OpenAI-compatible provider.

Mac/Linux:

```bash
export AI_DRAFT_MODE=api
export AI_API_KEY=your_key_here
# Optional provider overrides:
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

If the API returns quota or rate-limit errors, the app falls back to the local draft by default.

## Manual verification checklist

1. Open `http://localhost:8000/admin.php`.
2. Confirm the seeded **Welcome Packet** appears.
3. Create a document with the default publish time and confirm it shows as **Published**.
4. Generate a share link and confirm the success banner shows a readable token like `calm-shore-35`.
5. Open the generated share link and confirm the recipient can view the document.
6. Create a document with a future publish time and confirm it shows as **Scheduled**.
7. Try sharing the scheduled document and confirm the app does not allow sharing yet.
8. Use **Find by title** to search for a published document and create a share from the result.
9. Click **Draft with AI** with no API key configured and confirm local draft text appears.
10. Run `docker compose exec app php tests/test.php` and confirm all tests pass.

## What I would do with more time

- Add edit and reschedule support for existing documents.
- Add proper staff authentication instead of the seeded `current_staff()` assumption.
- Add CSRF protection to forms and the draft API endpoint.
- Add a visible audit log page for staff/admin review.
- Add share expiration, revocation, and access tracking.
- Increase readable-token entropy for a higher-volume production deployment.
- Add pagination to the document list and search results.
- Add browser-level tests for the main user flows.
- Improve AI drafting with district-specific templates and stricter content review guardrails.

## Repository map

```text
Dockerfile                         PHP 8.3 CLI image with SQLite and curl support
docker-compose.yml                 Local app service and AI environment configuration
schema.sql                         Base SQLite schema
migrations/001_add_publish_at.sql  Scheduled publishing migration
migrate.php                        Manual migration runner
seed.php                           Recreates local database, runs migrations, seeds sample data
lib/bootstrap.php                  DB connection, audit logging, scheduling, tokens, AI helpers
lib/migrations.php                 Migration runner used by seed and migrate.php
public/admin.php                   Staff document creation, scheduling, AI draft UI, document table
public/share.php                   Search documents and create readable-token share links
public/view.php                    Recipient document view with publish-time enforcement
public/draft_api.php               POST endpoint for AI/local draft generation
public/assets/style.css            UI styling
tests/test.php                     Lightweight test runner and feature tests
TAKEHOME_NOTES.md                  Detailed implementation report and walkthrough notes
```

## Summary

The implementation prioritizes a complete, reviewable product slice over a broad but fragile rewrite. Scheduled publishing protects recipient visibility, readable share tokens improve day-to-day usability, share-by-name makes staff workflows faster, and AI drafting demonstrates product thinking while remaining safe to run without external quota. The app remains simple to start, inspect, and test with Docker.
