# Job engine

Background work — backups, restores, migration imports — never runs in one
request. Shared hosts kill PHP after 30–60 seconds; WP-Cron only runs on
visits. The engine therefore splits every job into resumable steps.

## Pieces

| Class | Role |
|---|---|
| `Job_Handler` (interface) | `start($job)`, `step($job, $deadline)`, `cleanup($job)` |
| `Job_Store` | State files in `.work/`: `current.json`, `queue.json`, `last.json`, `runner.key`, `runner.lock` |
| `Job_Runner` | Queue, tick, hand-over, cancellation, logging, run rows |
| `Transfer` | Resumable multi-file upload/download over drivers, used by several handlers |

## A job

```php
[
  'id'       => 'a1b2c3d4e5f60718',   // random
  'type'     => 'backup',             // backup | restore | pull
  'trigger'  => 'schedule',           // manual | schedule | pre-update | cli | migration | safety
  'params'   => [...],                // handler input
  'status'   => 'running',            // queued | running | done | failed | cancelled
  'phase'    => 'archive', 'progress' => 37, 'message' => 'Archiving files: …',
  'state'    => [...],                // handler-owned, must be JSON-serialisable
  'warnings' => [], 'error' => '', 'result' => [...], 'run_id' => 12, 'attempts' => 0,
]
```

## Ticks and hand-over

```
enqueue() ──► queue.json ──► kick(): ─┬─ wp_schedule_single_event(+60s, ekbm_job_tick)
                                      └─ non-blocking POST admin-ajax.php?action=ekbm_tick (HMAC key)
tick():  flock(runner.lock, NB) ─► current.json or next from queue
         ─► handler->step($job, now + budget) ─► save ─► finished? end() : kick()
```

* **flock**, not an option-based lock: the OS releases it when the process
  dies, so a killed request never leaves a stale lock.
* **Atomic writes**: state is written to a temp file and renamed.
* **Retries**: an exception in a step increments `attempts`; after 3 the job fails.
* **Cancellation** uses a flag file (`cancel.flag`), because the running step
  holds the state in memory and would overwrite a field in it.
* **Loopback authentication**: the unauthenticated endpoint only accepts
  `HMAC-SHA256('ekbm-tick', runner.key)` and can only advance an existing job.
* **Polling**: `wp_ajax_ekbm_status` runs an 8-second tick when the lock is free,
  so a manually started job finishes even if loopbacks and cron are blocked.
* **Synchronous mode**: `Job_Runner::run_now()` (WP-CLI, pre-update backups)
  loops `step()` in-process and bypasses the queue.

## Idempotent steps

A step can be killed after writing data but before its state is saved. Each
handler must tolerate re-running the last step:

* append-only files (scan list, SQL dump) are truncated back to the size saved
  with the state (`Backup_Job::truncate()`);
* ZIP parts are only modified through `ZipArchive::close()`, which writes a temp
  file and renames — a killed close leaves the previous part intact;
* downloads write `*.part` and rename when complete;
* the database restore only touches temporary tables until the final swap.

## Adding a job type

1. Implement `Job_Handler`; keep `state` small and JSON-serialisable.
2. Register it with the `ekbm_job_handlers` filter (or in `Job_Runner::handler()`).
3. Fill `$job['result']` (`backup_id`, `size`, `components`, …) for the report.
4. Use `Job_Runner::log()` / `warn()` for the log; never echo.
