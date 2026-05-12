# Take-home notes

## What the team is asking for

The assignment is intentionally open-ended: understand the existing document-sharing app, choose the most valuable scope for roughly three hours, and ship thoughtful product changes with tests. I prioritized a complete scheduled-publishing path first, then added AI drafting as a product differentiator, and used human-readable share tokens for the readable-link requirement.

## Three-hour prioritization

1. **Scheduled publishing** is the highest product-completeness risk because recipients must not see a document before its intended publish time.
2. **AI document drafting** is the differentiator: it helps district staff start compliant public-facing documents faster, but gracefully disappears when `OPENAI_API_KEY` is not configured.
3. **Human-readable share tokens** improve usability for links people may read aloud or paste into emails while keeping the feature small enough to verify.
4. **Share by name** remains a pragmatic title search for published documents only, using exact, prefix, then partial matching.

## How to run locally after forking

```bash
git clone <your-fork-url>
cd folio-takehome
# Optional, only if testing AI drafting:
export OPENAI_API_KEY=your_key_here
docker compose up --build
```

Open <http://localhost:8000>. The app seeds a fresh `db.sqlite` on startup, applies migrations, and prints a sample share link.

Run tests from another terminal while the container is running:

```bash
docker compose exec app php tests/test.php
```

If PHP is installed locally, you can also run the lightweight test script without Docker:

```bash
php tests/test.php
```

## What to verify manually

1. Create a document with no publish time and confirm its generated slug share link opens immediately.
2. Create a document with a future publish time and confirm the share page does not allow sharing yet; the automated tests also cover the recipient not-yet-available guard for any pre-created future share token.
3. Set `OPENAI_API_KEY`, restart Docker Compose, enter a title, and confirm **Draft with AI** fills the body and logs an `ai_draft` audit event.
4. Use **Find by title** on the admin page to search by part of a published title and create a share from the result.
