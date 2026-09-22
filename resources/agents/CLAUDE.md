# AI Harness

- Run Laravel commands through `./.ai-harness artisan ...`.
- Run tests through `./.ai-harness test ...`.
- Run Composer, PHP, and npm through `./.ai-harness composer ...`, `./.ai-harness php ...`, and `./.ai-harness npm ...`.
- Use `./.ai-harness up`, `./.ai-harness down`, and `./.ai-harness doctor` for the configured local environment.
- Do not bypass the configured runtime unless the user explicitly asks.
- Cloud detection uses `CLAUDE_CODE_REMOTE=true` or explicit `AI_HARNESS_ENV=claude-cloud|codex-cloud`; `local` overrides detection. Cloud commands use native PHP and local services.
- Cloud SessionStart prepares ordinary clones and resumed sessions. SessionEnd removes only owned testing databases and preserves development data. Multi-repository sessions need explicit `./.ai-harness cloud setup` per project because repository hooks are not loaded.
