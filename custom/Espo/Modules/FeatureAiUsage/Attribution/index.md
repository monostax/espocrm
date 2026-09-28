# AI usage attribution

- `Reconciler.php` — fill missing conversation links from account-scoped source IDs, preserving waivers.
- `Job.php` — retry resolvable pending runs every five minutes, up to 500 per tick.

Public entry point: `Reconciler::reconcile()`; internal billing waivers remain independent of attribution.
