# Laravel AI Harness

Laravel AI Harness prepares each checkout's local environment and routes development commands through its configured runtime. Follow the AI Harness section in `AGENTS.md` for command syntax; Laravel Boost provides the general Laravel guidance.

1. Read `.ai-harness.config.dist`, `.ai-harness.config`, and `.ai-harness.config.local` in that order when determining the effective configuration. Linked local worktrees also inherit the primary checkout's `.ai-harness.config.local` before their own local file. Later files override earlier ones. `doctor` reports the loaded sources.
2. `runtime` selects native PHP, Herd, Valet, or Sail for application commands. `services=sail` can provide containers such as MySQL even when the PHP runtime is Herd, Valet, or native; do not infer the runtime from running containers.
3. Setup and cleanup are scoped to the current checkout's environment and harness-owned resources. The harness does not run migrations or update, select, or create Git branches.
4. Set personal application defaults with `local_env.VARIABLE=value` in `.ai-harness.config.local`, then run setup to apply them to `.env`. Use `local_env.FORWARD_DB_PORT` for managed Sail MySQL; generated database names and site URLs remain managed. Local overrides are ignored in cloud execution and retain isolated testing defaults. Do not print override values or copy a primary checkout's entire `.env` into a worktree.
