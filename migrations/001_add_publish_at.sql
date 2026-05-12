-- Migration 001: scheduled publishing
-- Adds publish_at to documents. Existing rows are backfilled to created_at so
-- every pre-existing document is treated as already published.
ALTER TABLE documents ADD COLUMN publish_at TEXT;

UPDATE documents
SET publish_at = created_at
WHERE publish_at IS NULL;

CREATE INDEX idx_documents_publish_at ON documents(publish_at);
