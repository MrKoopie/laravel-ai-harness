# AI Harness

- Run Laravel commands through `./.ai-harness artisan ...`.
- Run tests through `./.ai-harness test ...`.
- Run Composer, PHP, and npm through `./.ai-harness composer ...`, `./.ai-harness php ...`, and `./.ai-harness npm ...`.
- In Codex, when planning to execute a command that invokes Laravel Herd, run that command outside the sandbox on the first attempt (`sandbox_permissions=require_escalated`). This applies to direct `herd ...` and `ai-herd ...` commands, and to `./.ai-harness ...` commands that will invoke Herd under the active runtime. Request escalation only for the planned command, not merely because Herd is configured.
- Use `./.ai-harness up`, `./.ai-harness down`, and `./.ai-harness doctor` for the configured local environment.
- Do not bypass the configured runtime unless the user explicitly asks.
- Cloud detection uses `CLAUDE_CODE_REMOTE=true` or explicit `AI_HARNESS_ENV=claude-cloud|codex-cloud`; `local` overrides detection. Cloud commands use native PHP and local services.
- In cloud sessions, run `./.ai-harness cloud maintain` after restoring a cached checkout. Use `./.ai-harness cloud cleanup` to remove only owned testing databases; development data is preserved.
- In Claude cloud sessions, SessionStart prepares ordinary clones and resumed sessions. SessionEnd removes only owned testing databases and preserves development data. Multi-repository sessions need explicit `./.ai-harness cloud setup` per project because repository hooks are not loaded.
