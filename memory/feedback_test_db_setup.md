---
name: Test database setup conventions
description: Glaurlink tests use a dynamic test_<rand> database created in bootstrap.php from tests/.env credentials. Tests must create/drop real tables (not TEMPORARY) and the DB is auto-dropped on shutdown.
type: feedback
---

Test databases must be created dynamically with a random name (test_<1-1000>) and the bootstrap must fail-fast if the DB already exists. Credentials come from tests/.env.

**Why:** User explicitly requires ephemeral test databases that don't risk colliding with real data — no hardcoded DB names, no silent drops.

**How to apply:** When adding tests, use the `TEST_DB_*` constants from bootstrap.php. Create real tables (not TEMPORARY) in setUp and DROP them in tearDown.