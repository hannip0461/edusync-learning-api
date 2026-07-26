# Decisions

## D1 — SQL Server is the primary execution target

The application uses SQL Server 2022 and PDO_SQLSRV because its lock hints, duplicate codes, and Classic ASP integration are SQL Server specific. MySQL differences are documented separately and are not presented as tested behavior.

## D2 — Ledger and snapshot are separate

`learning_events` preserves every accepted input while `lecture_progress` is the current read model. This makes late events auditable without allowing them to overwrite newer resume state.

## D3 — Insert event before snapshot and decide duplicates after rollback

The unique `(source, event_id)` insert is first. On 2601/2627 the transaction rolls back, then a fresh hash lookup distinguishes an idempotent replay from a conflicting payload. This avoids a pre-insert race.

## D4 — Authentication normalizes source

Learner Bearer and player HMAC middleware set source from configuration; clients cannot send it. HMAC covers the original raw body and timestamp. Static credentials are limited to local development.

## D5 — Resume, furthest, and completion have different rules

Resume follows the latest ordered event and can move backward. Furthest is monotonic. Completion is a first completion timestamp, not a progress-state toggle.

## D6 — Classic ASP stays read-only

The Classic ASP artifact uses parameterized ADO BIGINT parameters and an IIS Application connection string. Its source and JSON fixture are contract-tested; no new write coupling is added.

## D7 — Keep application wiring explicit

Slim 4, manual wiring, PDO_SQLSRV, and focused PHP tests are sufficient for this service. An ORM, queue, or DI framework would add complexity without improving the SQL Server transaction boundary.

## D8 — Lock the snapshot key range

`UPDLOCK,HOLDLOCK` on the unique learner/lecture index serializes the missing-row and existing-row cases. It is used after event insert so either ledger and snapshot commit together or both roll back.

## D9 — Test deterministic database behavior, not timing luck

Parallel HTTP workers rendezvous on a test-only DB barrier. The deadlock case uses two one-row test tables and reverse updates; no production flag or fixed overlap sleep is used. Every fixture uses a unique run prefix and is removed in `finally` blocks.
