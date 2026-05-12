# Folio Take-Home

A small document-sharing app. You'll be extending it with features that customers have been asking for.


## What this branch adds

The take-home asks us to extend Folio, a small document-sharing app, around three customer needs: scheduled publishing, readable document/share identifiers, and easier sharing by name. I treated the fuzzy parts of the prompt as product decisions and focused on changes that make the app more complete without turning a small PHP project into a large framework.

In this branch I shipped:

1. **Scheduled publishing** — staff can set a publish date/time when creating a document. Before that time, recipients see a not-yet-available message instead of the document body.
2. **AI-assisted drafting** — staff can generate a first draft from a title. It defaults to a local no-cost draft mode, so the app still works without API keys or paid quota. API mode can be enabled for OpenAI-compatible providers.
3. **Human-readable share tokens** — new share links use readable tokens such as `calm-shore-35` instead of long hex strings.
4. **Share by name** — staff can search published documents by title or ID before creating a share link.
5. **Migrations and tests** — schema changes run through a small migration runner, and the lightweight PHP test script covers the new behavior.

## Problem and approach

The original app already had the main document-sharing loop: create a document, generate a token, and let a recipient view the document. The biggest missing product behavior was control over *when* recipients can see a document. I started there because early access to a scheduled document is the highest-risk issue for a compliance-focused government tool.

After scheduled publishing was working end to end, I added AI drafting as a practical productivity feature. During local testing, API quota became a real problem, so I changed the design to use local no-cost draft mode by default and keep external API mode optional. That makes the feature reliable during review and still shows how the app can integrate with an AI provider.

For readable identifiers, I chose to make the recipient share token human-readable. This keeps the existing URL shape and avoids a larger document-routing rewrite while still improving the part of the product that users copy, paste, and read aloud.

Finally, I added title search to the share flow so staff do not need to scroll through the full document table to find the right document.

## Step-by-step implementation notes

### 1. Database migration

I added a small migration system instead of editing the base schema directly. The migration adds `publish_at` to `documents`, backfills existing rows, and adds an index. `seed.php` runs migrations after loading `schema.sql`, so a fresh `docker compose up` still creates a working database.

Relevant files:

- `migrations/001_add_publish_at.sql`
- `lib/migrations.php`
- `migrate.php`
- `seed.php`

### 2. Scheduled publishing

The admin form now includes a publish date/time. New documents default to publishing immediately, preserving the old behavior. Recipient views check `is_published()` before showing the document body, and future documents show a clear not-yet-available message.

Relevant files:

- `public/admin.php`
- `public/view.php`
- `public/share.php`
- `lib/bootstrap.php`

### 3. AI drafting

The admin page includes a **Draft with AI** button. By default it uses local no-cost draft mode, which means no API key is required. If you want to test an external provider, set `AI_DRAFT_MODE=api` and provide `AI_API_KEY` or `OPENAI_API_KEY`. The API response is audited with source/model details, and quota or rate-limit errors fall back to local mode by default.

Relevant files:

- `public/admin.php`
- `public/draft_api.php`
- `lib/bootstrap.php`
- `docker-compose.yml`
- `Dockerfile`

### 4. Human-readable share tokens

Share creation now uses tokens like `cobalt-river-47`. The success banner shows the readable token separately from the full URL so it is obvious which part is the human-readable identifier.

Relevant files:

- `lib/bootstrap.php`
- `public/share.php`
- `seed.php`

### 5. Share by name

The share page now supports searching published documents by title or ID. The search order prefers exact title matches, then title prefixes, then partial matches. I kept this intentionally simple because it is predictable and fits SQLite without extra dependencies.

Relevant files:

- `public/share.php`
- `public/admin.php`

### 6. Tests

The original repo used a small PHP test script instead of PHPUnit. I kept that pattern and added coverage for the new features: scheduled visibility, readable tokens, title search, audit logging, and AI local/API modes.

Relevant file:

- `tests/test.php`

## Running locally

### Basic run

```bash
docker compose up --build
```

Open:

```text
http://localhost:8000
```

The app reseeds `db.sqlite` on each startup so reviewers always begin from a known state.

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

```bash
docker compose exec app php tests/test.php
```

### Manual migration command

```bash
php migrate.php
```

## AI drafting modes

### Default: local no-cost mode

No key is required:

```bash
docker compose up --build
```

The **Draft with AI** button generates a local first draft that staff can review and edit.

### Optional: API mode

Mac/Linux:

```bash
export AI_DRAFT_MODE=api
export AI_API_KEY=your_key_here
# Optional: export AI_MODEL=gpt-4o-mini
# Optional: export AI_API_BASE_URL=https://api.openai.com/v1/chat/completions
docker compose up --build
```

Windows PowerShell:

```powershell
$env:AI_DRAFT_MODE="api"
$env:AI_API_KEY="your_key_here"
docker compose up --build
```

If API quota is exceeded or the provider rate-limits the request, the app falls back to local draft mode by default so the workflow still works.


## Background

Folio is a small tool that lets staff create documents and share them with recipients via one-time links. This repo contains a staff admin page, document creation, share-link generation, and a recipient view. The schema (`schema.sql`) and helpers (`lib/bootstrap.php`) are meant to feel representative of a real internal tool.

Take some time to read the code before you start building.

## Agent setup

How you configure this repo for AI-assisted work is part of the exercise. That can include context files, permissions, hooks, custom commands, conventions to follow, orchestration (subagents, parallel tasks, custom skills or commands) — whatever fits how you work.

We're not prescribing specifics. Commit what you'd commit on a real project. If you decide setup isn't worth it for a three-hour exercise, say so in your video and explain why.

## Your Task

Customers have asked for three things. Pick an order, scope as you see fit, and build as much as you can in the time you have.

### 1. Scheduled publishing

Staff should be able to prepare a document in advance and have it become visible to recipients at a specific date and time. Before that time, someone hitting the share link should see a "not yet available" message instead of the document.

### 2. Human-readable document IDs

Today documents are identified by auto-increment integers (`#1`, `#2`) and share links use opaque hex tokens. Customers want each document to have a **short, readable ID** — something a person could say out loud, type into a URL, or paste into an email. Examples of the shape (not prescriptive): `welcome-2026`, `onboarding-packet-3k`, `FOLIO-7QX4`.

The exact format, length, and URL structure are your call. Think about collisions, guessability, and how this interacts with the existing share-token mechanism.

### 3. Share by name

Staff should be able to find a document to share by searching for it by title, not just by scrolling a list. Decide what "search" means here — exact match, prefix, fuzzy, something else — and justify your choice.

## What we're intentionally not specifying

- Whether readable IDs **replace** the existing share-token mechanism or **complement** it (there are real tradeoffs either way — privacy, guessability, link permanence)
- The URL structure for viewing a document
- How you structure and run schema migrations (see below)
- How the three features interact with each other

Make these calls yourself and explain your reasoning in your video. We care about your judgment as much as your code.

## Requirements

- **Schema changes go through a migration file (or files) you add to the repo**, not by editing `schema.sql` directly. There is no migration system yet — you decide how to organize one. Explain your approach in your video.
- At least one test covers each feature you build (see `tests/test.php` for the existing pattern).
- Document creation, scheduling changes, and share actions should be logged to `audit_log` (pattern is in `lib/bootstrap.php`).
- The `docker compose up` flow should still work from a fresh clone for anyone reviewing your branch.

## Deliverables

1. A branch with your changes and a commit log that tells the story of your work
2. A short video (~5 min) walking us through your approach, covering:
   - What you built and what you scoped out
   - The design decisions you made and the alternatives you rejected
   - Anything in the existing code you noticed worth flagging
   - What you'd do with more time
   - **Your AI workflow**: what you leaned on AI for, what you did yourself, a moment you pushed back on a suggestion, and anything you noticed about where AI helped or hurt
3. *(Optional)* Share chat transcripts or links if it's easy — a thoughtful minute in the video is worth more than an unedited log.

## Time

Budget ~3 hours. You probably won't finish all three features — **that's expected**. Prioritize, ship what you can finish well, and explain what you skipped and why. Partial + thoughtful beats rushed + complete.

## What we're looking for

- How you handle ambiguity (the spec is intentionally fuzzy)
- How you gather context before writing code
- How you set up and work with AI tools — including when you push back on their suggestions
- How you verify your own work
- How you communicate tradeoffs and anything surprising you found

Finished-but-sloppy loses to unfinished-but-thoughtful.
