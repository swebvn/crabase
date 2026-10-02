# Crabase

A Codex-style shared coding workspace, built with React, PHP Webman/Workerman, and SQLite. The PHP worker talks directly to `codex app-server` over JSONL stdin/stdout. Node also runs the local PTY host used by the integrated terminal.

## Run

Requires macOS or Linux, PHP 8.1+ with PDO SQLite, pcntl and posix, Composer, Node 22.12+, and an installed, authenticated Codex CLI.

```sh
npm install
composer install --working-dir=server
npm run build
cd server
vendor/bin/phinx migrate
php bin/create-admin.php  # First install only; prompts securely for credentials
vendor/bin/phinx seed:run  # Optional: add this repository as a project
php start.php start
```

Open **http://127.0.0.1:8787**. Stop the server with Ctrl+C, or `php start.php stop` from `server/`. For background operation: `php start.php start -d`.

For frontend development, keep PHP running and run `npm run dev` in another terminal. Open http://127.0.0.1:5173. Vite proxies the WebSocket to PHP; production serves compiled static files directly from Webman.

If `codex` is not on PHP's PATH, set `CODEX_BIN` to its absolute executable path before starting PHP. Authentication and model defaults come from the local Codex CLI configuration. Run `codex login` separately if necessary. `CRABASE_DB` optionally overrides the SQLite path (mainly for isolated testing).

## Accounts and first admin

### Prompt attachments

Attach with the paperclip, drag files onto the composer, or paste clipboard images/files (when exposed by the browser). Ordinary text paste is unchanged. Each message accepts up to 10 files, 5 MB each; empty files are rejected. Upload chips show progress, removal and retry. Sending waits for all uploads, and failed sends keep the draft. Attachments also work for notes and new project-less chats.

Uploads use small authenticated WebSocket chunks, staged privately under `.artifacts/.uploads`, with at most 30 pending files per user. Abandoned uploads expire after 24 hours and are cleaned hourly and on startup/upload. Sending moves each file once into `.artifacts/<chat-id>` and stores its original name and metadata on the message. Include `.artifacts` in backups. Supported raster images are sent to Codex as local image inputs (40-megapixel limit); other files are provided as paths for the agent to read, not automatically executed or parsed. HTML/SVG and other non-raster files download rather than render in the app. No OCR/document-conversion service is added.

### First admin

There is no public password signup and no built-in/default password. Configured TDA Passport login can create regular user accounts from provider identities. After migrating, run `php server/bin/create-admin.php` from the repository root. It prompts for a unique display name, login email and password without echoing the password, and refuses to bootstrap a second enabled admin. Use a long, unique password (6–72 bytes accepted). Credentials are stored only as password hashes in the local database, not source files.

Sign in, then use **Settings → Admin → Users** to list, create, edit, disable users or reset their passwords. The final enabled administrator cannot be disabled or demoted. Disabled accounts and password resets revoke their sessions. If all admins lose access, recover from a database backup or use a reviewed operator recovery procedure; there is no unauthenticated password-reset endpoint.

**Settings → Profile** manages the display name, uploaded profile photo and optional Git author name/email. Login email is read-only, including for administrators. Changing a password requires the current password and signs the user out. After login, users without a working avatar or Gravatar must upload a photo in Profile before entering the workspace. Photos accept PNG, JPEG, GIF or WebP up to 5 MB and are stored in the `avatars` directory beside the configured SQLite database; include this directory in backups. Gravatar is a third-party service and receives the email hash and image request. Git emails can be GitHub-verified or `noreply` addresses; only configured participants receive agent-requested `Co-authored-by` trailers. These trailers are instructions to the agent, not a Git hook enforcing every manual commit.

Sessions last seven days in HttpOnly, SameSite=Strict cookies. The HTTP shell/assets may load without login, but workspace data, WebSocket commands and artifact downloads require an enabled account. Login allows ten attempts per connection IP per fifteen minutes; loopback/SSH users share that limit. Cookies are intentionally non-Secure for this loopback HTTP setup; HTTPS deployment requires reviewed secure-cookie/origin configuration before exposure. Optional TDA Passport OAuth2 is described below.

Existing historical identities remain for message attribution but cannot sign in until an operator explicitly migrates them to accounts. The initial admin command creates a new user rather than silently granting access to historical identities.

### TDA Passport OAuth2 (local deployment)

Register the exact redirect URL `http://127.0.0.1:8787/auth/oauth/callback` with TDA Passport. Put `TDA_PASSPORT_OAUTH_CLIENT_ID` and `TDA_PASSPORT_OAUTH_CLIENT_SECRET` in the ignored `.env` (never frontend code or Git), migrate, and restart PHP. The login screen then offers **Sign in with TDA**. PHP cURL with TLS support is required. The authorization, token and user endpoints are fixed to `https://passport.tdagroup.online/oauth/authorize`, `/oauth/token`, and `/api/user` respectively.

The integration uses authorization code + S256 PKCE, browser-bound one-use state (10-minute expiry), verified TLS, and server-side token exchange. Provider access tokens are used only to fetch the profile, then discarded. First login requires a top-level `id` and valid `email` from `/api/user`; email verification is not required. A matching enabled account is linked; otherwise a regular user account is created using the provider name and email. Disabled accounts remain blocked, and provider roles never grant administrator access. Later logins use the stored provider ID binding. Passport-created accounts have no known local password; an administrator can set one if needed. Existing password login remains available. Enabling Passport therefore grants Passport users access to this shared workspace. Login completes on `127.0.0.1:8787`, including when initiated from Vite. The OAuth flow cookie is HttpOnly/SameSite=Lax so the provider redirect can complete; normal session cookies remain Strict. This fixed loopback callback is not public-deployment configuration.

## Deploy — internal team VPS

Our deployment target is one VPS for a trusted team, reached over the team's private mesh network. Use the systemd setup below; Docker, Composer-global installation, and a public installer are not needed. The intended CI/CD flow is **push to `main` → test/build in GitHub Actions → deploy the tested commit over SSH**. This section documents the procedure; a deployment workflow and `crabase update` command are **not implemented yet**.

Crabase requires login through email/password or configured TDA Passport OAuth2. Projects default to private; admins use **Settings → Admin → Projects** to grant enabled users access or choose Public (all signed-in users). Admins always have access, worktrees inherit their parent’s sharing, and standalone chats remain shared. Existing projects become private on migration. Access checks cover workspace requests, live updates, terminals and artifact downloads. OS isolation is not implemented: shell commands and agents still share the server account and can reach other folders, so members must remain trusted collaborators. For deployment, bind both listeners to loopback and connect through an SSH tunnel; the current all-interface bindings are for temporary LAN/NetBird testing. Do not expose ports 8787 or 8788 publicly.

### 1. Install prerequisites

The VPS needs Git, Composer, PHP 8.1+ with PDO SQLite, `pcntl`, and `posix`, Node 22.12+, npm, and the Codex CLI. On Ubuntu/Debian, install the system packages first:

```sh
sudo apt update
sudo apt install -y git unzip sqlite3 php-cli php-sqlite3 php-mbstring php-curl composer
php -m | grep -E 'pcntl|posix|pdo_sqlite'
node --version
npm --version
composer --version
codex --version
```

Install a current Node release and the Codex CLI using their official instructions if the distribution packages do not meet the versions above.

### 2. Create a dedicated user and install Crabase

Run the application and Codex under the same unprivileged account so the persistent worker can read that account's Codex authentication and only the intended workspace folders.

```sh
sudo adduser --disabled-password --gecos '' crabase
sudo -iu crabase
git clone https://github.com/bangnokia/crabase.git ~/crabase
cd ~/crabase
npm ci
composer install --working-dir=server --no-dev --optimize-autoloader
mkdir -p server/runtime ~/workspaces
cp .env.example .env
```

Edit `.env` for the VPS:

```dotenv
CRABASE_WORKSPACE_ROOT=/home/crabase/workspaces
CRABASE_AGENT_NAME=Crab
CRABASE_PARALLEL_CHATS=12
CRABASE_DB=/home/crabase/crabase/server/runtime/crabase.sqlite
CODEX_BIN=/absolute/path/from-command-v-codex
```

`CRABASE_WORKSPACE_ROOT` is the highest directory users may browse and select as a project. Keep it narrow. Set `CODEX_BIN` to the output of `command -v codex`; omit it only when `codex` is already on the service PATH.

Authenticate Codex as the service user, then build and initialize the database:

```sh
codex login
npm run build
cd server
vendor/bin/phinx migrate
# Optional: vendor/bin/phinx seed:run
php bin/create-admin.php  # First installation only
php start.php start
```

Confirm that http://127.0.0.1:8787 responds on the VPS, then stop the foreground process with Ctrl+C.

### 3. Run with systemd

Create `/etc/systemd/system/crabase.service` as root. Adjust the PHP path if `command -v php` is not `/usr/bin/php`.

```ini
[Unit]
Description=Crabase workspace
After=network.target

[Service]
Type=simple
User=crabase
Group=crabase
WorkingDirectory=/home/crabase/crabase/server
Environment=HOME=/home/crabase
Environment=PATH=/home/crabase/.local/bin:/usr/local/bin:/usr/bin:/bin
ExecStart=/usr/bin/php start.php start
ExecStop=/usr/bin/php start.php stop
Restart=on-failure
RestartSec=3
KillMode=mixed

[Install]
WantedBy=multi-user.target
```

Enable it and inspect its logs:

```sh
sudo systemctl daemon-reload
sudo systemctl enable --now crabase
sudo systemctl status crabase
sudo journalctl -u crabase -f
```

### 4. Connect privately

Join the same mesh network as the VPS, then use its mesh hostname or address for SSH. **The current application still requires localhost browser URLs**: joining the mesh does not make direct `http://<mesh-ip>:8787` access supported. HTTP/WS checks and the TDA callback are loopback-specific. From your computer, forward both listeners:

```sh
ssh -N \
  -L 8787:127.0.0.1:8787 \
  -L 8788:127.0.0.1:8788 \
  crabase@YOUR_VPS_MESH_HOST
```

Keep that terminal open and visit http://127.0.0.1:8787 locally. The browser loads the built frontend through port 8787 and connects to the WebSocket through the forwarded port 8788.

Each team member opens their own tunnel and signs in with their own Crabase account. Keep local ports 8787 and 8788 free. For TDA login, register `http://127.0.0.1:8787/auth/oauth/callback` and configure the client ID/secret on the VPS as described above; the browser callback reaches the VPS through the tunnel. Do not put OAuth secrets or the service user's Codex credentials in frontend build variables.

If UFW is enabled, allow SSH but do not add rules for Crabase's ports:

```sh
sudo ufw allow OpenSSH
sudo ufw enable
sudo ufw status
```

### 5. Persistent data and backups

Keep this application checkout dedicated to deployment—do not use it as a team coding project. With the paths above, preserve:

| Path | Contents |
| --- | --- |
| `/home/crabase/crabase/.env` | Server configuration and OAuth secret |
| `/home/crabase/crabase/server/runtime/` | Default SQLite database, uploaded avatars, standalone-chat folders and runtime files |
| `/home/crabase/workspaces/` | Original project repositories, `.worktrees`, `.chats`, `.artifacts` and staged attachments |
| `/home/crabase/.codex/` | Codex configuration, authentication and thread history (or the configured Codex data directory) |

If `CRABASE_DB` is overridden, back up that database and its adjacent `avatars/` directory too. Worktrees depend on the original repositories' Git metadata: preserve both, including ignored project data such as `.env` and SQLite files. Never run `git clean -fdx` or deploy with a broad `rsync --delete` across these paths.

Before stopping the service, ask the team to pause changes, let queued/running jobs finish or cancel them, and close terminals and preview processes. Stop Crabase so no new jobs arrive, then back up. The commands below assume the default database path from step 2:

```sh
sudo systemctl stop crabase
sudo -iu crabase
cd ~/crabase
mkdir -p /home/crabase/backups
chmod 700 /home/crabase/backups
deploy_backup=$(mktemp -d /home/crabase/backups/deploy-XXXXXXXX)
sqlite3 server/runtime/crabase.sqlite \
  ".backup '$deploy_backup/crabase.sqlite'"
cp -a .env "$deploy_backup/env"
cp -a server/runtime "$deploy_backup/runtime"
cp -a /home/crabase/workspaces "$deploy_backup/workspaces"
cp -a /home/crabase/.codex "$deploy_backup/codex"
git rev-parse HEAD > "$deploy_backup/commit"
```

These backups contain secrets; keep them private and copy them to protected off-host storage. Large workspaces can use filesystem snapshots instead of full copies. Do not let other processes write project databases during the backup.

### 6. Manual update

After the backup, while still in the service-user shell at `~/crabase`, update the deployment checkout. It must have no tracked local changes; investigate any changes rather than resetting them:

```sh
git status --short
git pull --ff-only
npm ci
composer install --working-dir=server --no-dev --optimize-autoloader
npm run build
php server/vendor/bin/phinx migrate -c server/phinx.php
exit
sudo systemctl start crabase
sudo systemctl status crabase
curl --silent --show-error --include http://127.0.0.1:8787/auth/session
```

Run commands one at a time and **stop on any failure**; do not start the service after a failed install, build or migration. The unauthenticated session check should return HTTP `401` with a JSON response containing `user: null`; a connection error or `5xx` is a deployment failure. Check sign-in, WebSocket connectivity, opening a project file and a terminal through the tunnel. The session endpoint alone does not verify the agent or terminal host.

### 7. Internal CI/CD — push to deploy

When we add the workflow, use this sequence:

1. On pull requests and pushes to `main`, use an isolated runner with PHP/extensions, Composer, Git, Node/npm and Python 3. Run `npm ci`, `composer install --working-dir=server`, `npm run build`, and `npm test`. Tests use disposable data and ports; **do not run them on the live application host**.
2. Only a successful push to protected `main` may deploy. Save the built `server/public/` files as an artifact tied to that exact commit SHA. Never build with the production `.env` or Codex credentials.
3. Give the deployment job private mesh access to the VPS, or use a dedicated trusted runner already on the mesh. GitHub-hosted runners cannot reach a private mesh address without that connection. Do not run untrusted PR jobs on a runner with production access.
4. Use a GitHub deployment environment for the SSH key, mesh credentials and host settings. Pin the SSH host key using a fingerprint verified outside CI; do not disable host-key checking. Limit service-control privileges to the Crabase unit.
5. Serialize deployments with a concurrency group; do not cancel a deployment halfway through migrations. Until we implement an active-job/terminal drain check, require operator approval after the team pauses work. Push-to-deploy must not silently interrupt active sessions.
6. Record the previous deployed SHA, stop the service and make the backups above. Fetch and deploy the **exact tested SHA**, not whatever happens to be the latest `main`. Install PHP dependencies on the VPS and run `npm ci --omit=dev` there for the Node PTY runtime; do not upload a runner's platform-specific `node_modules`.
7. Install that SHA's frontend artifact into `server/public/`, run migrations, start systemd and perform the checks from step 6. Record the deployed SHA and retain the previous artifact and backup. Deployment stops on failure and reports which step failed.

Start with this in-place deployment checkout to keep operations simple. If we later use versioned release folders, keep `.env`, the entire runtime directory, workspace data and Codex storage at stable shared paths; changing `CRABASE_DB` alone does not relocate standalone-chat folders.

### 8. Restore or roll back

Stop Crabase first. For a code-only failure with a compatible database schema, redeploy the previous tested SHA, its dependency versions and frontend artifact. Do not automatically run `phinx rollback` on production.

If a migration or data change requires restoring a backup, preserve the failed state first and restore the database and matching runtime/workspace/Codex data to their original paths and ownership. Use SQLite's restore operation while the app is stopped rather than replacing a database underneath live WAL connections. Restore `.env` only if needed. A data restore loses changes made after the backup; coordinate it with the team before restarting. Never run migration rollback commands against the only copy of workspace data.

For failures, check `journalctl -u crabase`, confirm the service user's `CODEX_BIN`, run `codex login` as that user, verify directory ownership, and run `vendor/bin/phinx status` from `server/`.

## Included

- Responsive desktop-style interface, light/dark themes, project sidebar, search (⌘/Ctrl K), new-thread shortcut (⌘/Ctrl N), composer, and activity panel.
- Add existing project folders; persistent threads, attributed notes, archives, and activity.
- Live updates between browser tabs through a Workerman WebSocket process.
- Real Codex requests, streamed responses, terminal/file activity, command/file approval dialogs, cancellation, and queued requests.
- SQLite in WAL mode, busy timeout, foreign keys, prepared statements, and short transactions.

Existing installations may retain the three getting-started conversations; fresh databases no longer create them automatically. In the composer, **Enter** or the send arrow invokes the agent; the note icon only saves a message. Notes are not automatically injected into the agent's context. Project paths are selected on the server's filesystem, not uploaded from a browser.

## Current boundary

This is a **trusted-team foundation**, not a public multi-tenant deployment. Sessions and project sharing protect workspace commands and artifact downloads, but users still share filesystem and terminal privileges. Add OS isolation, TLS and a reviewed deployment configuration before public exposure.

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

Regular chats have no project. A project is any existing readable directory on the shared machine, whether or not it contains code or Git. Selecting a project starts its threads in that directory. Standalone agent chats use a private scratch directory under `CRABASE_WORKSPACE_ROOT/.chats/<chat-id>`. Existing project associations are preserved during migration.

## Collaboration accounts

Only administrators can register projects or browse the project-creation folder picker. Members can use existing projects and create chats within them.

Create accounts in Settings → Admin → Users. Use separate browser profiles to test different signed-in users; tabs in the same browser profile share the session cookie. The old `?user=` switch is ignored. Messages and notes are attributed to the server-authenticated user.

## Model and reasoning

The composer loads the actual `model/list` catalog from the persistent Codex process over WebSocket. Choose a model and one of its supported reasoning levels. Choices are remembered per tab, validated on the backend, and saved with each queued job; changing the picker does not change an already queued turn. Each agent turn sends its selected `model` and `effort` to Codex. Notes do not invoke a model. The process starts when the first browser connects so the catalog is available before sending a prompt.

The UI uses locally bundled DM Sans with 15px chat/composer text and larger supporting labels; fonts require no external font service.

## Agent display name

The agent is called **Crab** by default. Edit `server/config/crabase.php` or start the backend with an environment override:

```sh
CRABASE_AGENT_NAME="Your agent name" php server/start.php start
```

Restart the running backend after configuration changes. This names the agent in the UI, including historical assistant-message headers, composer, approval prompts, and new activity. Codex remains the underlying runtime; model names and message content are not renamed.

### Generated files

Deliverables are stored persistently under the configured `CRABASE_WORKSPACE_ROOT` in `.artifacts/<chat-id>/`. The agent generates them there and runs a publish command that registers the file and returns its browser URL; files created elsewhere are copied in. Images can preview in chat; all file types can download through the local PHP server. Existing raw filesystem links must be republished. Back up `.artifacts` alongside the database; do not treat it as a temporary directory.

### Database migrations (Phinx)

Run from `server/`. Phinx and the application use the same `CRABASE_DB` path (default `server/runtime/crabase.sqlite`), including `.env`. Configuration is in `server/phinx.php`; versioned PHP migrations live in `server/database/migrations/`.

```bash
vendor/bin/phinx status
vendor/bin/phinx create AddChatDescription
vendor/bin/phinx migrate
vendor/bin/phinx rollback  # Latest migration; development database only
vendor/bin/phinx migrate   # Reapply after editing an unshipped migration
```

Use explicit `up()` and `down()` methods for changes that cannot be automatically reversed by `change()`. Once deployed, leave migration files unchanged and add another migration. Phinx records applied versions in `phinxlog`. SQLite migrations run transactionally; irreversible data loss requires a backup, even when schema rollback is possible.

For isolated development, prefix **every command** with `CRABASE_DB=/absolute/path/dev.sqlite`. Each initial table has its own migration. Rolling back drops the latest table/change; `rollback -t 0` drops all application tables and their data. Do not run it against the workspace database you want to keep. Artifact files are outside migration scope.

Deployment: stop the server, create a consistent SQLite backup using SQLite's backup API (including WAL contents), run `vendor/bin/phinx migrate`, and start the server only after success. Workers no longer create, upgrade, or seed tables on connection. `vendor/bin/phinx seed:run` optionally registers the Crabase project; it does not insert dummy conversations.

The initial table migrations can adopt the current pre-Phinx schema without rewriting application rows. It rejects older/incompatible columns; upgrade those databases using the previous application release first. Always back up before adoption.

### Models and users

`server/app/model/` contains Webman Eloquent models for users, accounts, sessions, projects, chats, messages, jobs, approvals, events, settings and pending uploads. Relationships connect projects to chats and users to their messages. `Actions` validates input and coordinates model operations; Phinx owns migrations. `config/database.php` uses SQLite with foreign keys and Webman's connection pool. The Codex worker uses models, with per-chat FIFO selection owned by `Job::nextQueued` and atomic streaming owned by `Message::appendBody`. Visible writes explicitly bump the revision through `Store::notify`; bulk model updates do not emit model events. Revisions and writes share the same transaction. Authentication/session/upload bookkeeping stays silent. Bound SQL remains for atomic message appends, revision/throttle increments and one-use OAuth state consumption; short-lived/composite-key OAuth bookkeeping uses the query builder. `Store::all/run` remain for existing test/operator scripts, not application CRUD.

Users persist in SQLite with stable IDs, names, avatar URLs, and creation timestamps. Existing human message authors are backfilled into `messages.user_id`. The WebSocket boundary derives message identity from the authenticated session and ignores client `user_id`. Accounts contain private login and optional Git identity fields; workspace snapshots expose only public display identity. Optional TDA Passport OAuth2 links existing accounts or creates regular users on first login. Project visibility and membership are enforced through `ProjectAccess`; do not expose the service publicly without OS isolation.

### Parallel agent chats

The backend runs one persistent `codex app-server` subprocess and communicates through JSON-RPC over stdin/stdout. Different chats can run concurrently; messages in the same chat remain sequential. The default is 12 active chats, configurable with `CRABASE_PARALLEL_CHATS=12` in `.env`. Restart the backend after changing it. Cancelling one chat does not cancel other chats. Chats in the same working folder share its files.

### Feature worktrees

Administrators can choose **Create worktree** from an active project's menu and enter a new branch name (for example `feature/login`). The project must be a Git repository root with a commit. The new branch starts at its current `HEAD`; uncommitted changes, ignored `.env`, dependencies and SQLite files are not copied. Install dependencies and configure test data separately in the worktree terminal. Preview-server automation is not included.

New worktrees are stored at `<CRABASE_WORKSPACE_ROOT>/.worktrees/<safe-project-name>/<random-word-pair>/`, for example `.worktrees/daudau.cc/wobbly-otter/`, outside the original project. Folder names are reserved atomically; existing folders are never overwritten. The random folder name is independent of the Git branch shown in the UI. Existing ID-based paths remain supported and are not moved automatically. Worktrees appear as branch-icon groups beneath the project's direct chats. Each group can contain many chats and shows five recent chats plus Load more. Worktree groups sort by latest chat activity; their activity also counts toward the original project's Latest update sort. Each worktree has a separate record linked to its parent, so the editor, Git changes, agent and terminal automatically use its folder.

Creation uses a bounded local Git checkout (10-second command timeout); very large repositories may need manual provisioning. A failure after Git creation preserves the folder/branch for recovery. Archiving hides records without deleting files. Deleting a worktree removes its local folder using `git worktree remove` and then its chats/record. Close its terminals and stop active jobs first. Uncommitted/untracked files trigger an extra **Delete anyway?** confirmation; cancelling leaves the folder and chats untouched, while confirming permanently discards those files. Locked worktrees are not forcibly removed. **Ignored local files such as `.env`, SQLite databases and dependencies are deleted too**; back up needed data before confirming. The Git branch is retained, so committed files remain recoverable. Delete child worktrees before deleting their original project record; the original project folder is never removed. Back up `.worktrees` along with the original repositories—the worktrees depend on their shared Git metadata. Git worktrees are not a security boundary.
