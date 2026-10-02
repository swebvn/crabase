# Crabase agent instructions

Crabase is a local shared workspace: React/Vite frontend, PHP Webman/Workerman backend, SQLite, and a persistent `codex app-server` worker.

## Commands

- `pnpm run build` — typecheck and production frontend build.
- `npm test` — model/effort and WebSocket integration tests.
- `php server/start.php start` — run HTTP on `127.0.0.1:8787` and WebSocket on `127.0.0.1:8788`.
- `php server/start.php stop` — stop the server.

## Rules

- Read `design.md` for UI changes. Keep shared tokens in `web/src/styles/tokens.css` and components/pages separate from WebSocket and routing hooks.
- Default to compact padding: 12px settings/page gutters, 4px vertical / 8px horizontal list rows, 8–12px form gaps. Never stack inherited label margins with parent gaps; avoid oversized or nested padded wrappers.
- Follow DRY for real shared behavior: equivalent UI paths must use the same component and CSS contract (standalone and project chats both use `ChatLink`). Reuse or extend source-owned primitives in `web/src/components/ui.tsx` before duplicating interaction markup; do not extract speculative wrappers.

- Keep browser workspace traffic on the existing WebSocket protocol (`{id, action, data}` and `patch` events); do not reintroduce polling or GET/POST refresh loops.
- Keep SQLite writes prepared, short, and compatible with WAL mode and foreign keys.
- Use Eloquent models for domain queries and writes. Keep `Store` for connection setup, validation, snapshots and revision notifications, not new raw CRUD. Notify after visible writes (`Store::notify($affectedRows)` for bulk updates); model/builder writes do not notify automatically. Keep bound SQL only for atomic operations the ORM cannot express safely, and document why. Never read/modify/save streamed message bodies; use `Message::appendBody`.
- Validate untrusted paths, message text, model names, reasoning levels, origins, and command inputs at the backend boundary.
- Do not require email verification for TDA Passport login; accept the provider email after validating its format.
- The Codex worker is persistent and owns the single shared agent queue. Do not spawn a new Codex process per message.
- Agent display name is configurable through `server/config/crabase.php` / `CRABASE_AGENT_NAME`; default is `Crab`.
- Projects are optional existing readable folders. Standalone chats must remain project-less.
- Worktrees reuse project records with `parent_id` pointing to the original project; chats reference the record for their actual working folder. New managed paths live at `<workspace-root>/.worktrees/<safe-project-name>/<random-word-pair>`; legacy ID-based paths remain supported. Reserve random folder names atomically and never rename active worktrees automatically. Use `Project::workspacePath()` for editor/terminal access. Worktree deletion uses validated `git worktree remove`, rejects active jobs/terminals and locked worktrees, and retains branches. Dirty worktrees return `requires_confirmation`; only an explicit second confirmation sends boolean `force: true` to discard local changes. Warn that ignored local files are removed. Never delete the original project folder; require deleting child worktrees before removing its record.
- Do not expose loopback listeners publicly. Email/password sessions are required on HTTP data and WebSocket boundaries; derive message identity from the session, never client user_id. Projects default private; admins manage visibility and membership, public means all signed-in users, and worktrees inherit the original project's access. Use ProjectAccess for request, snapshot, push and artifact authorization. Standalone chats remain shared. OS isolation is not implemented: terminals and agents share the server account, so signed-in members remain trusted collaborators. Never expose password/session hashes in snapshots. Preserve the last enabled admin.
- Run `pnpm run build` and `npm test` after changes. Keep the implementation minimal.
