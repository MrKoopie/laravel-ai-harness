# AI Harness

- Run Laravel commands through `./.ai-harness artisan ...`.
- Run tests through `./.ai-harness test ...`.
- Run Composer, PHP, and npm through `./.ai-harness composer ...`, `./.ai-harness php ...`, and `./.ai-harness npm ...`.
- Use `./.ai-harness up`, `./.ai-harness down`, and `./.ai-harness doctor` for the configured local environment.
- Do not bypass the configured runtime unless the user explicitly asks.
- Cloud detection uses `CLAUDE_CODE_REMOTE=true` or explicit `AI_HARNESS_ENV=claude-cloud|codex-cloud`; `local` overrides detection. Cloud commands use native PHP and local services.
- In cloud sessions, run `./.ai-harness cloud maintain` after restoring a cached checkout. Use `./.ai-harness cloud cleanup` to remove only owned testing databases; development data is preserved.
