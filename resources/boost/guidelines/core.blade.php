# Laravel AI Harness

Laravel AI Harness prepares each checkout's local environment and routes development commands through its configured runtime. Follow the AI Harness section in `AGENTS.md` for command syntax; Laravel Boost provides the general Laravel guidance.

1. Read `.ai-harness.config.dist`, `.ai-harness.config`, and `.ai-harness.config.local` in that order when determining the effective configuration. Later files override earlier ones.
2. `runtime` selects native PHP, Herd, or Sail for application commands. `services=sail` can provide containers such as MySQL even when the PHP runtime is Herd or native; do not infer the runtime from running containers.
3. Setup and cleanup are scoped to the current checkout's environment and harness-owned resources. The harness does not run migrations or update, select, or create Git branches.
