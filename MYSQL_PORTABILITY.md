# MySQL portability notes

This project has not been executed against MySQL. These are implementation differences to resolve before any port.

- SQL Server `IDENTITY` plus `OUTPUT INSERTED...` maps to `AUTO_INCREMENT` and a different generated-key retrieval pattern. MySQL cannot reuse the current `OUTPUT` SQL.
- SQL Server duplicate codes 2601/2627 and deadlock code 1205 differ from MySQL duplicate key 1062 and deadlock 1213. Retry classification must be rewritten and tested on the target driver.
- SQL Server snapshot behavior, `UPDLOCK,HOLDLOCK`, and the named unique-index range lock have no direct MySQL syntax equivalent. InnoDB isolation level, next-key/gap locks, and an explicit `SELECT ... FOR UPDATE` strategy need a separate race review.
- `datetime2(3)` and `SYSUTCDATETIME()` need an explicit UTC `DATETIME(3)` convention. Driver timezone conversion and fractional precision must be tested rather than assumed.
- SQL Server's insert-first duplicate path is not a reason to use MySQL `INSERT ... ON DUPLICATE KEY UPDATE`; that upsert can blur the required hash comparison and snapshot ordering. Preserve the ledger-first decision boundary explicitly.
