# Crabase

A Codex-style shared coding workspace, built with React, CrabPHP, and SQLite. The PHP worker talks directly to `codex app-server` over JSONL stdin/stdout. Node also runs the local PTY host used by the integrated terminal.

## Run

Requires macOS or Linux, PHP 8.4+ with PDO SQLite, pcntl and posix, Composer, Node 22.12+, and an installed, authenticated Codex CLI.

```sh
npm install
composer install --working-dir=server
npm run build
cd server
php cua migrate
php bin/create-admin.php  # First install only; prompts securely for credentials
php bin/seed-workspace.php  # Optional: add this repository as a project
php cua start
```

Open **http://127.0.0.1:8787**. Stop the server with Ctrl+C, or `php cua stop` from `server/`. For background operation: `php cua start -d`.

Run the normal `start` command for the full workspace: it starts the HTTP server and the persistent Codex WebSocket on port `8788`. The framework's `--single` mode intentionally serves HTTP only, so it is not suitable for chat or Codex sessions.

For frontend development, keep PHP running and run `npm run dev` in another terminal. Open http://127.0.0.1:5173. Vite proxies the WebSocket to PHP; production serves compiled static files directly from CrabPHP.

For temporary development from a trusted LAN or NetBird mesh, bind both listeners to all interfaces and allow the mesh network explicitly. Replace the network with the range used by your mesh:

```sh
CRABASE_BIND_HOST=0.0.0.0 \
CRABASE_ALLOWED_HOSTS=100.99.0.0/16 \
php server/cua start
pnpm exec vite --host 0.0.0.0
```

Open `http://<mesh-ip>:5173` in the browser. `CRABASE_BIND_HOST` defaults to `127.0.0.1`, and the host allow-list remains empty unless configured, so ordinary local development stays loopback-only.

If `codex` is not on PHP's PATH, set `CODEX_BIN` to its absolute executable path before starting PHP. Authentication and model defaults come from the local Codex CLI configuration. Run `codex login` separately if necessary. `CRABASE_DB` optionally overrides the SQLite path (mainly for isolated testing).

## Accounts and first admin

### Prompt attachments

Attach with the paperclip, drag files onto the composer, or paste clipboard images/files (when exposed by the browser). Ordinary text paste is unchanged. Each message accepts up to 10 files, 5 MB each; empty files are rejected. Upload chips show progress, removal and retry. Sending waits for all uploads, and failed sends keep the draft. Attachments also work for notes and new project-less chats.

Uploads use small authenticated WebSocket chunks, staged privately under `.artifacts/.uploads`, with at most 30 pending files per user. Abandoned uploads expire after 24 hours and are cleaned hourly and on startup/upload. Sending moves each file once into `.artifacts/<chat-id>` and stores its original name and metadata on the message. Include `.artifacts` in backups. Supported raster images are sent to Codex as local image inputs (40-megapixel limit); other files are provided as paths for the agent to read, not automatically executed or parsed. HTML/SVG and other non-raster files download rather than render in the app. No OCR/document-conversion service is added.

### First admin

There is no public password signup and no built-in/default password. Configured TDA Passport login can create regular user accounts from provider identities. After migrating, run `php server/bin/create-admin.php` from the repository root. It prompts for a unique display name, login email and password without echoing the password, and refuses to bootstrap a second enabled admin. Use a long, unique password (6–72 bytes accepted). Credentials are stored only as password hashes in the local database, not source files.

Sign in, then use **Settings → Admin → Users** to list, create, edit, disable users or reset their passwords. The final enabled administrator cannot be disabled or demoted. Disabled accounts and password resets revoke their sessions. If all admins lose access, recover from a database backup or use a reviewed operator recovery procedure; there is no unauthenticated password-reset endpoint.

**Settings → Profile** manages the display name, uploaded profile photo and optional Git author name/email. Login email is read-only, including for administrators. Changing a password requires the current password and signs the user out. After login, users without a working avatar or Gravatar must upload a photo in Profile before entering the workspace. Photos accept PNG, JPEG, GIF or WebP up to 5 MB and are stored in the `avatars` directory beside the configured SQLite database; include this directory in backups. Gravatar is a third-party service and receives the email hash and image request. Git emails can be GitHub-verified or `noreply` addresses; only configured participants receive agent-requested `Co-authored-by` trailers. These trailers are instructions to the agent, not a Git hook enforcing every manual commit.

Sessions last seven days in HttpOnly, SameSite=Strict cookies. The HTTP shell/assets may load without login, but workspace data, WebSocket commands and artifact downloads require an enabled account. Login allows ten attempts per client IP per fifteen minutes. Behind the production Caddy proxy each browser address has its own limit; direct loopback/SSH users share one. Cookies are marked `Secure` automatically when `APP_URL` is an `https://` origin (see [Production deployment](#production-deployment)); local loopback HTTP keeps them non-Secure. Optional TDA Passport OAuth2 is described below.

Existing historical identities remain for message attribution but cannot sign in until an operator explicitly migrates them to accounts. The initial admin command creates a new user rather than silently granting access to historical identities.

### TDA Passport OAuth2

Register the exact redirect URL `APP_URL` + `/auth/oauth/callback` with TDA Passport (locally `http://127.0.0.1:8787/auth/oauth/callback`; in production your HTTPS domain). Put `TDA_PASSPORT_OAUTH_CLIENT_ID` and `TDA_PASSPORT_OAUTH_CLIENT_SECRET` in the ignored `.env` (never frontend code or Git), migrate, and restart PHP. The login screen then offers **Sign in with TDA**. CrabPHP's HTTP client handles the TLS requests. The authorization, token and user endpoints are fixed to `https://passport.tdagroup.online/oauth/authorize`, `/oauth/token`, and `/api/user` respectively.

The integration uses authorization code + S256 PKCE, browser-bound one-use state (10-minute expiry), verified TLS, and server-side token exchange. Provider access tokens are used only to fetch the profile, then discarded. First login requires a top-level `id` and valid `email` from `/api/user`; email verification is not required. A matching enabled account is linked; otherwise a regular user account is created using the provider name and email. Disabled accounts remain blocked, and provider roles never grant administrator access. Later logins use the stored provider ID binding. Passport-created accounts have no known local password; an administrator can set one if needed. Existing password login remains available. Enabling Passport therefore grants Passport users access to this shared workspace. Login completes on the `APP_URL` host, including when initiated from Vite. The OAuth flow cookie is HttpOnly/SameSite=Lax so the provider redirect can complete; normal session cookies remain Strict.

## Production deployment

One VPS runs everything: a dedicated `crab` user owns the checkout, Crabase listens only on loopback (`8787` HTTP, `8788` WebSocket), Caddy serves your domain over HTTPS with automatic certificates, and Cua's daemon mode keeps it running (started at boot by a `crab` crontab line). Administrators install new releases from **Settings → Admin → Updates**.

Tested on Ubuntu 24.04 LTS; Debian 13 works with the note in step 1. You need a domain (for example `crabase.example.com`) whose DNS `A`/`AAAA` record points to the VPS, and inbound ports 80 and 443 open.

OS isolation is not implemented: agents and terminals run as the `crab` user, so every signed-in member must be a trusted collaborator. Projects default to private; admins share them from **Settings → Admin → Projects**.

### 1. Install system packages

Run as a sudo-capable user:

```sh
sudo apt update
sudo apt install -y software-properties-common git curl unzip caddy
sudo add-apt-repository -y ppa:ondrej/php        # Ubuntu 24.04 ships PHP 8.3; Crabase needs 8.4+
sudo apt install -y php8.4-cli php8.4-sqlite3 php8.4-mbstring
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo bash -
sudo apt install -y nodejs
curl -fsSL https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
php -m | grep -E 'pcntl|posix|pdo_sqlite|mbstring|fileinfo'   # all five must be listed
php --version && node --version && composer --version
```

On Debian 13, skip the `add-apt-repository` line and install `php-cli php-sqlite3 php-mbstring` (already PHP 8.4). Do not install Composer from apt on Ubuntu 24.04; that package pulls in PHP 8.3.

### 2. Create the service user and install Crabase

```sh
sudo adduser --disabled-password --gecos '' crab
sudo -iu crab
git clone https://github.com/swebvn/crabase.git ~/crabase
cd ~/crabase
git checkout "$(git tag --list 'v*' --sort=-v:refname | awk '!/-/{print; exit}')"   # latest stable release
mkdir -p server/runtime ~/workspace
cat > .env <<'EOF'
APP_URL=https://crabase.example.com
CRABASE_WORKSPACE_ROOT=/home/crab/workspace
CRABASE_AGENT_NAME=Crab
CODEX_BIN=/home/crab/.local/bin/codex
EOF
npm ci
composer install --working-dir=server --no-dev --optimize-autoloader
npm run build
php server/cua migrate
php server/bin/create-admin.php     # prompts for the first admin's name, email and password
exit
```

Replace `crabase.example.com` with your domain. `APP_URL` must be the exact browser origin: Crabase accepts that host and origin, and makes session cookies `Secure` when it is `https://`. `CRABASE_WORKSPACE_ROOT` is where projects, scratch chats, artifacts and worktrees live; keep it narrow. `CODEX_BIN` points at the Codex install from step 3. Optional settings: `CRABASE_PARALLEL_CHATS` (default 12), and `NODE_BIN` (absolute path) if Node is not in `/usr/bin` or `/usr/local/bin`, for example when it comes from nvm. Stay on a release tag rather than `main`; the in-app updater only upgrades release checkouts.

### 3. Set up Codex

Crabase runs one persistent `codex app-server` as the `crab` user. Install the official standalone Codex CLI for that user (no root and no Node needed; it lands in `~/.codex/packages/standalone/` with a `~/.local/bin/codex` link, which is what `CODEX_BIN` points at):

```sh
sudo -iu crab
curl -fsSL https://chatgpt.com/codex/install.sh | sh
~/.local/bin/codex --version
```

Then sign in with **one** of these, still as `crab`:

```sh
# a) ChatGPT account (Plus/Pro/Business): open the printed link on any device and enter the code
~/.local/bin/codex login --device-auth

# b) OpenAI API key, read without echoing it or saving it in shell history
read -rsp 'API key: ' key && printf '%s' "$key" | ~/.local/bin/codex login --with-api-key; unset key

~/.local/bin/codex login status     # must report that you are logged in / an API key is configured
exit
```

c) **OpenAI-compatible proxy or other provider:** sign in with that provider's key as in (b), then point Codex at it in `/home/crab/.codex/config.toml`:

```toml
model_provider = "team"
model = "your-default-model"          # optional; users pick a model per chat in Crabase

[model_providers.team]
name = "team"
base_url = "https://llm-proxy.example.com/v1"
wire_api = "responses"
requires_openai_auth = true           # send the key saved by `codex login --with-api-key`
```

Crabase sets the working folder, `danger-full-access` sandbox and `never` approvals for every chat itself, and lists models from Codex's `model/list`, so nothing else is required in `config.toml`. After Crabase starts (step 4), **Settings → Admin → Health** should show *Codex app-server: ok* and a non-zero model count. To update Codex later, re-run the installer as `crab`, then `php server/cua restart` in `~/crabase`.

### 4. Start Crabase and keep it running after reboots

Cua's daemon mode supervises and restarts its own workers, so no service manager is needed. As the `crab` user:

```sh
sudo -iu crab
cd ~/crabase
php server/cua start -d
php server/cua status
echo '@reboot cd $HOME/crabase && PATH=/usr/local/bin:/usr/bin:/bin php server/cua start -d' | crontab -
exit
```

The `@reboot` line (it replaces the `crab` user's crontab, so re-running is safe) starts Crabase when the VPS boots; its `PATH` must include `/usr/local/bin` so the in-app updater can find Composer. Day to day, as `crab` in `~/crabase`: `php server/cua restart`, `php server/cua stop`, `php server/cua status`.

### 5. Serve the domain over HTTPS (Caddy)

```sh
sudo tee /etc/caddy/Caddyfile >/dev/null <<'EOF'
crabase.example.com {
	reverse_proxy /ws 127.0.0.1:8788
	reverse_proxy 127.0.0.1:8787
}
EOF
sudo systemctl reload caddy
sudo ufw allow OpenSSH && sudo ufw allow 80,443/tcp && sudo ufw --force enable
curl -si https://crabase.example.com/auth/session | head -1     # expect HTTP/2 401
```

Caddy obtains and renews the certificate, forwards the WebSocket upgrade on `/ws`, and passes the browser address in `X-Forwarded-For`; Crabase trusts that header only from loopback, so login rate limits apply per user. Never open ports 8787 or 8788 in the firewall. Open `https://crabase.example.com` and sign in with the admin from step 2.

For TDA Passport login, register `https://crabase.example.com/auth/oauth/callback` (that is, `APP_URL` + `/auth/oauth/callback`), add `TDA_PASSPORT_OAUTH_CLIENT_ID` and `TDA_PASSPORT_OAUTH_CLIENT_SECRET` to `.env`, and run `php server/cua restart` as `crab` in `~/crabase`.

**Private access without a public domain:** put Caddy on loopback with `http://127.0.0.1:8080 { … }` (same two `reverse_proxy` lines), set `APP_URL=http://127.0.0.1:8787`, and have each member run `ssh -N -L 8787:127.0.0.1:8080 crab@VPS` and open `http://127.0.0.1:8787`.

### 6. Updates

Open **Settings → Admin → Updates** as an administrator. Crabase checks the latest stable GitHub release; the update button is enabled only when the checkout is on an older release tag, has no tracked local changes, and no agent jobs or terminals are active. Updating restarts the service and briefly disconnects everyone. The updater:

1. fetches the exact release tag, checks that Composer and npm are available, and stops Crabase;
2. backs up the database, `.env`, avatars and the built frontend to `server/runtime/update-backups/<time>/`;
3. checks out the tag, runs `composer install`, `npm ci`, `npm run build` and migrations, and starts Crabase again.

If any step fails after the stop, it restores the previous commit, its dependencies, frontend and database, and restarts the previous release. Progress is pushed live to the page and stored in `server/storage/update.json`; command output is in `server/storage/update.log`. Installations from a release older than the Updates page need one manual update first.

Manual update over SSH (same steps; stop on any failure and do not start after a failed build or migration):

```sh
sudo -iu crab
cd ~/crabase
php server/cua stop
git status --short                 # must print nothing; investigate rather than reset
git fetch --tags
git checkout vX.Y.Z
npm ci
composer install --working-dir=server --no-dev --optimize-autoloader
npm run build
php server/cua migrate
php server/cua start -d
exit
```

### 7. Backups

Preserve these paths; they contain secrets, so keep copies private and off-host:

| Path | Contents |
| --- | --- |
| `/home/crab/crabase/.env` | Configuration and OAuth secret |
| `/home/crab/crabase/server/runtime/` | SQLite database, avatars, standalone-chat folders, update backups |
| `/home/crab/workspace/` | Project repositories, `.worktrees`, `.chats`, `.artifacts`, staged attachments |
| `/home/crab/.codex/` | Codex configuration, authentication and thread history |

Ask the team to pause work, then:

```sh
sudo -iu crab
cd ~/crabase
php server/cua stop
mkdir -p -m 700 ~/backups
backup=$(mktemp -d ~/backups/$(date +%Y%m%d)-XXXX)
php -r '$db = new PDO("sqlite:".$argv[1]); $db->exec("VACUUM INTO ".$db->quote($argv[2]));' server/runtime/crabase.sqlite "$backup/crabase.sqlite"
cp -a .env server/runtime ~/workspace ~/.codex "$backup/"
git rev-parse HEAD > "$backup/commit"
du -sh "$backup"
php server/cua start -d
exit
```

Never run `git clean -fdx`, `rsync --delete` or `php server/cua migrate:rollback` against these paths on production. To restore, stop Crabase, copy the database and the matching runtime/workspace/Codex data back to the same paths with `crab` ownership, check out the commit recorded in the backup, reinstall dependencies and build as in step 6, then run `php server/cua start -d`. A restore loses changes made after the backup.

### 8. Troubleshooting

- `sudo -u crab php /home/crab/crabase/server/cua status` shows the workers; logs are in `server/storage/logs/server.log`, update output in `server/storage/update.log`.
- **Settings → Admin → Health** shows the Codex app-server, database, agent queue and terminal host.
- "Codex app-server not running" or no models: run `sudo -iu crab ~/.local/bin/codex login status`, check `CODEX_BIN` in `.env` points at an executable, and check `server/storage/logs/server.log`; then `php server/cua restart`.
- Sign-in or WebSocket rejected: `APP_URL` must match the address in the browser exactly, including `https://` and any port.
- Update fails with "Could not run composer/npm": Crabase was started without `/usr/local/bin` on its `PATH` (the updater checks before stopping anything). Restart it from a `crab` login shell, and keep the `PATH=` part of the `@reboot` line.
- Migration state: `sudo -iu crab php ~/crabase/server/cua migrate:status`.

### Push-to-deploy (planned)

A CI workflow is not included. When one is added: test and build on an isolated runner (never on the production host, never with production `.env` or Codex credentials), deploy only successful pushes to protected `main`, reach the VPS over a pinned SSH host key from a protected deployment environment, serialize deployments, require operator approval until active work can be drained, and run the same stop → backup → install → build → migrate → start sequence as step 6 for the exact tested SHA.

## Included

- Responsive desktop-style interface, light/dark themes, project sidebar, search (⌘/Ctrl K), new-chat shortcut (⌘/Ctrl Shift O), composer, and activity panel.
- Add existing project folders; persistent threads, attributed notes, archives, and activity.
- Live updates between browser tabs through CrabPHP's WebSocket server.
- Real Codex requests, streamed responses, terminal/file activity, command/file approval dialogs, cancellation, and queued requests.
- SQLite in WAL mode, busy timeout, foreign keys, prepared statements, and short transactions.

Existing installations may retain the three getting-started conversations; fresh databases no longer create them automatically. In the composer, **Enter** or the send arrow invokes the agent; the note icon only saves a message. Notes are not automatically injected into the agent's context. Project paths are selected on the server's filesystem, not uploaded from a browser.

## Current boundary

This is a **trusted-team foundation**, not a public multi-tenant deployment. Sessions and project sharing protect workspace commands and artifact downloads, but users still share filesystem and terminal privileges. The production setup adds HTTPS and login on a public domain, but every account is a trusted collaborator; add OS isolation before giving access to untrusted users.

Chats can run concurrently, so chats sharing a working folder can still make conflicting edits. Use separate worktrees for independent features. Direct edits by someone on the machine can still conflict with an agent's edits. Codex runs with `danger-full-access` and `never` approvals using the local account's credentials. Unsupported interactive server requests receive an explicit error; forms and other advanced desktop integrations are not implemented.

Conversation display data lives in `server/runtime/crabase.sqlite`; agent context lives in Codex's own thread storage. Back up both to preserve the full workspace. App archiving hides the chat locally and does not archive the upstream Codex thread. Interrupted server runs are marked failed on restart and can be resumed by sending another message.

## Layout

```text
web/src/App.tsx            App composition and actions
web/src/pages/             New-chat and conversation pages
web/src/components/        Shared interface components
web/src/hooks/             WebSocket, routing, preferences
web/src/lib/               Pure route, identity, and streaming helpers
web/src/styles/tokens.css   Design tokens for both themes
web/src/style.css          Component layouts and states
web/tests/                 Native Node frontend checks
design.md                  Crabase design system
server/app/service/Actions.php  Validated WebSocket commands
server/app/service/Store.php    Connection setup, validation, snapshots and revision notifications
server/app/process/Codex.php    Codex lifecycle, queue, events, WebSocket updates
server/runtime/            Local data and logs (ignored)
```

## Check

With the PHP server running:

```sh
npm run build
npm test
```

The smoke check uses two real WebSocket clients to verify commands, origin rejection, validation, persisted notes, incremental text updates, reconnect recovery, standalone chats, folder threads, and archive/restore. It simulates a Codex-style append on its own test note, then archives its test threads; it does not invoke a model or modify project files. Codex integration was also manually checked against the installed CLI with a text-only request without tool use.

## WebSocket protocol

After static HTML/JS/CSS load, all workspace data and commands use one WebSocket connection. There are no GET/POST refresh loops or HTTP fallback. Clients send `{id, action, data}` and receive `{id, result}` or `{id, error}`. `sync` provides initial state and subscribes to an optional `chat_id`; subsequent `patch` events contain changed state collections, message upserts, text appends, and pending approvals. Reconnects resynchronize state; unacknowledged writes are reported as uncertain and never automatically replayed.

Regular chats have no project. A project is any readable, non-hidden directory on the shared machine, whether or not it contains code or Git; administrators can select an existing directory or create a child folder from the project picker. Selecting a project starts its threads in that directory. Standalone agent chats use a private scratch directory under `CRABASE_WORKSPACE_ROOT/.chats/<chat-id>`. Existing project associations are preserved during migration.

## Collaboration accounts

Only administrators can register projects or browse the project-creation folder picker. Members can use existing projects and create chats within them.

Create accounts in Settings → Admin → Users. Use separate browser profiles to test different signed-in users; tabs in the same browser profile share the session cookie. The old `?user=` switch is ignored. Messages and notes are attributed to the server-authenticated user.

## Model and reasoning

The composer loads the actual `model/list` catalog from the persistent Codex process over WebSocket. Choose a model and one of its supported reasoning levels. Choices are remembered per tab, validated on the backend, and saved with each queued job; changing the picker does not change an already queued turn. Each agent turn sends its selected `model` and `effort` to Codex. Notes do not invoke a model. The process starts when the first browser connects so the catalog is available before sending a prompt.

The UI uses locally bundled Inter, with 14px chat text and a 16px composer. Fonts require no external font service.

## Agent display name

The agent is called **Crab** by default. Edit `server/config/crabase.php` or start the backend with an environment override:

```sh
CRABASE_AGENT_NAME="Your agent name" php server/cua start
```

Restart the running backend after configuration changes. This names the agent in the UI, including historical assistant-message headers, composer, approval prompts, and new activity. Codex remains the underlying runtime; model names and message content are not renamed.

### Generated files

Deliverables are stored persistently under the configured `CRABASE_WORKSPACE_ROOT` in `.artifacts/<chat-id>/`. The agent generates them there and runs a publish command that registers the file and returns its browser URL; files created elsewhere are copied in. Images can preview in chat; all file types can download through the local PHP server. Existing raw filesystem links must be republished. Back up `.artifacts` alongside the database; do not treat it as a temporary directory.

### Database migrations (CrabPHP)

Run from `server/`. CrabPHP and the application use the same `CRABASE_DB` path (default `server/runtime/crabase.sqlite`), including `.env`. Configuration is in `config/database.php`; versioned PHP migrations live in `database/migrations/`.

```bash
php cua migrate:status
php cua make:migration add_chat_description
php cua migrate
php cua migrate:rollback  # Latest batch; development database only
php cua migrate           # Reapply after editing an unshipped migration
```

Each migration returns a CrabPHP `Migration` object with explicit `up()` and `down()` methods. Once deployed, leave migration files unchanged and add another migration. CrabPHP records applied names and batches in `migrations`. SQLite migrations run transactionally; irreversible data loss requires a backup, even when schema rollback is possible.

For isolated development, prefix **every command** with `CRABASE_DB=/absolute/path/dev.sqlite`. Each initial table has its own migration. `migrate:rollback` takes back the latest batch; `migrate:fresh` drops every table and reruns all migrations, destroying data. Do not run it against the workspace database you want to keep. Artifact files are outside migration scope.

Deployment: stop the server, create a consistent SQLite backup using SQLite's backup API (including WAL contents), run `php cua migrate`, and start the server only after success. Workers no longer create, upgrade, or seed tables on connection. `php bin/seed-workspace.php` optionally registers the Crabase project; it does not insert dummy conversations.

The first Crab migration adopts an existing Phinx ledger when present, preserving application rows and removing the old `phinxlog` table. The initial table migrations can also adopt the current pre-migration schema without rewriting application rows. They reject older/incompatible columns; upgrade those databases using the previous application release first. Always back up before adoption.

### Models and users

`server/app/model/` contains application-owned records backed by CrabPHP's query builder for users, accounts, sessions, projects, chats, messages, jobs, approvals, events, settings and pending uploads. Relations are explicit query methods, and JSON/boolean casts plus hidden credential fields are mapped by the record wrapper. `Actions` validates input and coordinates model operations; CrabPHP owns migrations. `config/database.php` uses SQLite with foreign keys and one Crab connection pool. The Codex worker uses models, with per-chat FIFO selection owned by `Job::nextQueued` and atomic streaming owned by `Message::appendBody`. Visible writes explicitly bump the revision through `Store::notify`; bulk model updates do not emit model events. Revisions and writes share the same transaction. Authentication/session/upload bookkeeping stays silent. Bound SQL remains for atomic message appends, revision/throttle increments and one-use OAuth state consumption; short-lived/composite-key OAuth bookkeeping uses the query builder. `Store::all/run` remain for existing test/operator scripts, not application CRUD.

Users persist in SQLite with stable IDs, names, avatar URLs, and creation timestamps. Existing human message authors are backfilled into `messages.user_id`. The WebSocket boundary derives message identity from the authenticated session and ignores client `user_id`. Accounts contain private login and optional Git identity fields; workspace snapshots expose only public display identity. Optional TDA Passport OAuth2 links existing accounts or creates regular users on first login. Project visibility and membership are enforced through `ProjectAccess`; do not expose the service publicly without OS isolation.

### Parallel agent chats

The backend runs one persistent `codex app-server` subprocess and communicates through JSON-RPC over stdin/stdout. Different chats can run concurrently; messages in the same chat remain sequential. The default is 12 active chats, configurable with `CRABASE_PARALLEL_CHATS=12` in `.env`. Restart the backend after changing it. Cancelling one chat does not cancel other chats. Chats in the same working folder share its files.

### Feature worktrees

Administrators can choose **Create worktree** from an active project's menu and enter a new branch name (for example `feature/login`). The project must be a Git repository root with a commit. The new branch starts at its current `HEAD`; uncommitted changes, ignored `.env`, dependencies and SQLite files are not copied. Install dependencies and configure test data separately in the worktree terminal. Preview-server automation is not included.

New worktrees are stored at `<CRABASE_WORKSPACE_ROOT>/.worktrees/<safe-project-name>/<random-word-pair>/`, for example `.worktrees/daudau.cc/wobbly-otter/`, outside the original project. Folder names are reserved atomically; existing folders are never overwritten. The random folder name is independent of the Git branch shown in the UI. Existing ID-based paths remain supported and are not moved automatically. Worktrees appear as branch-icon groups beneath the project's direct chats. Each group can contain many chats and shows five recent chats plus Load more. Worktree groups sort by latest chat activity; their activity also counts toward the original project's Latest update sort. Each worktree has a separate record linked to its parent, so the editor, Git changes, agent and terminal automatically use its folder.

Creation uses a bounded local Git checkout (10-second command timeout); very large repositories may need manual provisioning. A failure after Git creation preserves the folder/branch for recovery. Archiving hides records without deleting files. Deleting a worktree removes its local folder using `git worktree remove` and then its chats/record. Close its terminals and stop active jobs first. Uncommitted/untracked files trigger an extra **Delete anyway?** confirmation; cancelling leaves the folder and chats untouched, while confirming permanently discards those files. Locked worktrees are not forcibly removed. **Ignored local files such as `.env`, SQLite databases and dependencies are deleted too**; back up needed data before confirming. The Git branch is retained, so committed files remain recoverable. Delete child worktrees before deleting their original project record; the original project folder is never removed. Back up `.worktrees` along with the original repositories—the worktrees depend on their shared Git metadata. Git worktrees are not a security boundary.
