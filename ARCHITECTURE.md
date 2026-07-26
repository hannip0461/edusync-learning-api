# Architecture

## Responsibility boundaries

- Slim routes and controllers translate HTTP, JSON, status codes, and correlation IDs.
- Auth middleware converts local Bearer or player HMAC credentials into one server-owned subject/source context. Payloads never choose `source`.
- `EventInput` rejects malformed, unknown, or non-canonical input before service logic.
- `ProgressService` owns target prechecks, retry scope, duplicate decision, and snapshot order.
- `ProgressRepository` owns parameterized SQL and SQL Server locking. No ORM or DI container is used.

## Write path

The repository checks learner, lecture, enrollment window, and future time before opening the write transaction. The transaction inserts `learning_events` first, then reads `lecture_progress` with `UPDLOCK, HOLDLOCK, INDEX(UQ_lecture_progress_learner_lecture)`, then inserts or updates the snapshot.

Duplicate key 2601/2627 rolls back first and compares payload hashes on a new autocommit query. Equal hashes return an idempotent duplicate; different hashes return 409. 1205 and 3960 retry the entire transaction once. Statements that can receive trigger result sets consume them so PDO_SQLSRV surfaces the SQL Server error to this retry boundary.

For one session, newer means higher `(sequence_no, occurred_at, event_seq)`. Across sessions it means higher `(occurred_at, received_at, event_seq)`. Older events remain in the ledger and may raise `furthest_position_seconds`; only newer events change resume and last-order fields. `completed_at` is first-write-only.

## Read boundary

Guardian read authentication first binds the token to one guardian subject. The path must match that subject and `guardian_links` must authorize the learner before progress rows are returned. The Classic ASP adapter remains a separate read-only integration and contract fixture.
