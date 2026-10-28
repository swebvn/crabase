import { Button } from "./components/ui";
import type { Project, TerminalSession } from "./types";
import { useAuth } from "./components/AuthGate";
import { lazy, Suspense, useCallback, useEffect, useRef, useState } from "react";
import { Check } from "lucide-react";
import { useRoute } from "./hooks/useRoute";
import { useWorkspace } from "./hooks/useWorkspace";
import { usePreferences } from "./hooks/usePreferences";
import { useProjectNavigation } from "./hooks/useProjectNavigation";
import { isSidebarShortcut } from "./lib/shortcuts";
import { updateRecord } from "./lib/layout";
import { chatPath, newChatPath } from "./lib/routes";
import { rootProject } from "./lib/projects";
import { Sidebar } from "./components/Sidebar";
import { Header } from "./components/Header";
import { DetailsPanel } from "./components/DetailsPanel";
import { CodePanel } from "./components/CodePanel";
import { TerminalPanel } from "./components/TerminalPanel";
import { Composer, type SendOptions } from "./components/Composer";
import { NewChatPage } from "./pages/NewChatPage";
import { ChatPage } from "./pages/ChatPage";
import { WorkspacePanel, type WorkspaceTab, type WorkspaceTabId } from "./components/WorkspacePanel";
import type { Command } from "./lib/commands";

const ProjectDialog = lazy(() => import("./components/ProjectDialog").then((module) => ({ default: module.ProjectDialog })));
const WorktreeDialog = lazy(() => import("./components/WorktreeDialog").then((module) => ({ default: module.WorktreeDialog })));
const SearchDialog = lazy(() => import("./components/WorkspaceDialogs").then((module) => ({ default: module.SearchDialog })));
const SettingsPage = lazy(() => import("./pages/SettingsPage").then((module) => ({ default: module.SettingsPage })));

type ThreadTab = WorkspaceTabId;
type ThreadTabState = ThreadTab | "start";
type ThreadWorkspaceLayout = {
  tab: ThreadTabState | "";
  seen: ThreadTab[];
  last: ThreadTabState;
  dock: "bottom" | "right";
};

function emptyThreadWorkspace(): ThreadWorkspaceLayout {
  return {
    tab: "",
    seen: [],
    last: "start",
    dock: (localStorage.getItem("crabase-workspace-dock") as "bottom" | "right") || (localStorage.getItem("crabase-terminal-dock") as "bottom" | "right") || "right",
  };
}

export function App() {
  const { user } = useAuth();
  const { route, navigate } = useRoute();
  useEffect(() => {
    if (user.avatar_required && route.page !== 'settings') navigate('/settings');
  }, [user.avatar_required, route.page, navigate]);
  const selected = route.page === "chat" ? route.id : "";
  const workspace = useWorkspace(selected);
  const { data, live, loaded, messages, approvals, error, setError, request, terminalChatId, hasMoreMessages, loadingEarlier, loadEarlier } =
    workspace;
  const preferences = usePreferences(data.users);
  const { chat, projectId, project, scope, focused, focusScope, unavailable, switchProject, overview, backFromSettings } = useProjectNavigation(route, data.projects, data.chats, loaded, user.id, navigate);
  const [worktreeProject, setWorktreeProject] = useState<Project | null>(null);
  const [, refreshDraft] = useState(0);
  const [busy, setBusy] = useState(false);
  const sending = useRef(false);
  const drafts = useRef(new Map<string, { current: string; chatId: string; version: number }>());
  const draftKey = selected ? `chat:${selected}` : `new:${projectId}`;
  if (!drafts.current.has(draftKey)) drafts.current.set(draftKey, { current: "", chatId: "", version: 0 });
  const draftRef = drafts.current.get(draftKey)!;
  const draftVersion = draftRef.version;
  function clearDraft() {
    draftRef.chatId = '';
    draftRef.current = "";
    draftRef.version += 1;
    refreshDraft((version) => version + 1);
  }
  const [dialog, setDialog] = useState<"" | "search" | "project" | "settings">(
    "",
  );
  const [sidebar, setSidebar] = useState(false);
  const [sidebarHidden, setSidebarHidden] = useState(
    () => localStorage.getItem("crabase-sidebar-hidden") === "true",
  );
  const workspaceKey = draftKey;
  const [workspaceLayouts, setWorkspaceLayouts] = useState<Record<string, ThreadWorkspaceLayout>>({});
  const currentWorkspace = workspaceLayouts[workspaceKey] || emptyThreadWorkspace();
  const workspaceTab = currentWorkspace.tab;
  const seenWorkspaceTabs = currentWorkspace.seen;
  const updateCurrentWorkspace = useCallback((update: (current: ThreadWorkspaceLayout) => ThreadWorkspaceLayout) => {
    setWorkspaceLayouts((layouts) => updateRecord(layouts, workspaceKey, emptyThreadWorkspace, update));
  }, [workspaceKey]);
  const setWorkspaceTab = useCallback((tab: ThreadTabState | "") => {
    updateCurrentWorkspace((current) => ({ ...current, tab, last: tab || current.last }));
  }, [updateCurrentWorkspace]);
  const setSeenWorkspaceTabs = useCallback((update: ThreadTab[] | ((seen: ThreadTab[]) => ThreadTab[])) => {
    updateCurrentWorkspace((current) => {
      const seen = typeof update === "function" ? update(current.seen) : update;
      return seen === current.seen ? current : { ...current, seen };
    });
  }, [updateCurrentWorkspace]);
  const pendingTerminalIds = useRef(new Set<string>());
  const details = workspaceTab === "artifacts";
  const code = workspaceTab === "code";
  const selectWorkspace = (tab: WorkspaceTab) => {
    setSeenWorkspaceTabs((seen) => {
      const nextSeen = seen.includes(tab) ? seen : [...seen, tab];
      return nextSeen;
    });
    setWorkspaceTab(tab);
  };
  const setWorkspace = (tab: WorkspaceTab) => {
    if (workspaceTab === tab) setWorkspaceTab("");
    else selectWorkspace(tab);
  };
  const openTerminal = async () => {
    try {
      const { terminal } = await request<{ terminal: TerminalSession }>("terminalOpen", { cols: 80, rows: 24 });
      const tab = `terminal:${terminal.id}` as const;
      pendingTerminalIds.current.add(terminal.id);
      setSeenWorkspaceTabs((seen) => {
        const nextSeen = seen.includes(tab) ? seen : [...seen, tab];
        return nextSeen;
      });
      setWorkspaceTab(tab);
    } catch (error) {
      setError((error as Error).message);
    }
  };
  const removeWorkspaceTab = (tab: WorkspaceTabId) => {
    if (tab.startsWith("terminal:")) {
      const id = tab.slice("terminal:".length);
      pendingTerminalIds.current.delete(id);
      workspace.dismissTerminal(id);
      void request("terminalClose", { terminal_id: id }).catch((error) => setError((error as Error).message));
    }
    setSeenWorkspaceTabs((seen) => {
      const nextSeen = seen.filter((item) => item !== tab);
      if (workspaceTab === tab) setWorkspaceTab(nextSeen[0] || "");
      return nextSeen;
    });
  };
  const [fileSearch, setFileSearch] = useState(false);
  const terminalId = workspaceTab.startsWith("terminal:") ? workspaceTab.slice("terminal:".length) : "";
  const terminal = !!terminalId;
  const toggleSidebar = () => {
    if (window.innerWidth <= 760) {
      setSidebarHidden(false);
      setSidebar((visible) => !visible);
    } else {
      setSidebar(false);
      setSidebarHidden((hidden) => !hidden);
    }
  };
  const commands: Command[] = [
    { id: "new-chat", label: "New chat", shortcut: "⌘⇧O", run: () => newChat() },
    ...user.admin ? [{ id: "new-project", label: "Open project", keywords: ["project"], run: () => setDialog("project") }] : [],
    ...selected ? [{ id: "terminal", label: "Open terminal", keywords: ["shell"], run: () => void openTerminal() }] : [],
    ...selected && project ? [{ id: "code", label: "Open code editor", keywords: ["editor"], run: () => selectWorkspace("code") }] : [],
    { id: "settings", label: "Open settings", run: () => navigate('/settings') },
    { id: "sidebar", label: "Toggle left sidebar", run: toggleSidebar },
    ...selected ? [{ id: "right-sidebar", label: "Toggle right sidebar", keywords: ["workspace", "panel"], run: () => workspaceTab ? setWorkspaceTab("") : restoreWorkspace() }] : [],
    { id: "archived", label: "Show or hide archived projects", keywords: ["archive"], run: () => window.dispatchEvent(new Event("crabase:toggle-archived")) },
  ];
  useEffect(() => {
    if (!loaded || terminalChatId !== selected) return;
    const activeIds = new Set(workspace.terminals.map((item) => `terminal:${item.id}`));
    workspace.terminals.forEach((item) => pendingTerminalIds.current.delete(item.id));
    setSeenWorkspaceTabs((seen) => {
      const next = seen.filter((tab) => !tab.startsWith("terminal:") || activeIds.has(tab) || pendingTerminalIds.current.has(tab.slice("terminal:".length)));
      for (const terminal of workspace.terminals) {
        const tab = `terminal:${terminal.id}` as WorkspaceTabId;
        if (!next.includes(tab)) next.push(tab);
      }
      if (next.length === seen.length && next.every((tab, index) => tab === seen[index])) return seen;
      return next;
    });
    if (workspaceTab.startsWith("terminal:") && !activeIds.has(workspaceTab) && !pendingTerminalIds.current.has(workspaceTab.slice("terminal:".length))) setWorkspaceTab("start");
  }, [loaded, selected, terminalChatId, workspace.terminals, workspaceTab]);
  const restoreWorkspace = () => {
    const last = currentWorkspace.last;
    if (last === "start" || (last === "code" && !project) || (last.startsWith("terminal:") && !workspace.terminals.some((item) => item.id === last.slice("terminal:".length)))) {
      return setWorkspaceTab("start");
    }
    if (last === "code" || last === "artifacts") return selectWorkspace(last);
    setSeenWorkspaceTabs((seen) => {
      if (seen.includes(last)) return seen;
      const next = [...seen, last];
      return next;
    });
    setWorkspaceTab(last);
  };
  const toggleTerminal = () => {
    if (terminal) return setWorkspaceTab("");
    const last = currentWorkspace.last;
    const existing = workspace.terminals.find((item) => last === `terminal:${item.id}`) || workspace.terminals.at(-1);
    if (existing) setWorkspaceTab(`terminal:${existing.id}`);
    else void openTerminal();
  };
  const workspaceDock = currentWorkspace.dock;
  const [toast, setToast] = useState("");
  function open(id: string) {
    setFileSearch(false);
    const target = data.chats.find(chat => chat.id === id);
    navigate(chatPath(id, focused ? rootProject(target?.project_id, data.projects)?.id : undefined));
    setSidebar(false);
    setDialog("");
    setError("");
  }
  function newChat(id = focused ? scope?.id || "" : "") {
    setFileSearch(false);
    navigate(newChatPath(id, focused));
    setSidebar(false);
    setDialog("");
    setError("");
  }
  function changeProject(id: string) {
    setFileSearch(false);
    switchProject(id);
    setSidebar(false);
    setDialog("");
    setError("");
  }
  function showOverview() {
    setFileSearch(false);
    overview();
    setSidebar(false);
    setDialog("");
    setError("");
  }
  useEffect(() => {
    const key = (event: KeyboardEvent) => {
      if (event.isComposing || event.repeat || document.querySelector('dialog[open]')) return;
      // Control chords belong to the shell while terminal input has focus.
      if (!event.metaKey && event.target instanceof Element && event.target.closest('.terminal-emulator')) return;
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "p" &&
        !event.shiftKey && !event.altKey && !event.isComposing && project) {
        if (document.querySelector('dialog[open]:not(.file-palette)')) return;
        event.preventDefault();
        selectWorkspace("code");
        setFileSearch(true);
        document.querySelector<HTMLInputElement>('.file-palette input')?.focus();
      }
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "k") {
        event.preventDefault();
        setDialog("search");
      }
      // Browsers reserve ⌘/Ctrl+N for a new window, so Shift+O is the shortcut that reaches a normal tab.
      if ((event.metaKey || event.ctrlKey) && !event.altKey && (event.key === "n" || (event.shiftKey && event.key.toLowerCase() === "o"))) {
        event.preventDefault();
        commands.find((command) => command.id === "new-chat")?.run();
      }
      if (isSidebarShortcut(event)) {
        event.preventDefault();
        commands.find((command) => command.id === "sidebar")?.run();
      }
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "j" && selected) {
        event.preventDefault();
        toggleTerminal();
      }
      if ((event.metaKey || event.ctrlKey) && event.key === "\\" && selected) {
        event.preventDefault();
        if (workspaceTab) setWorkspaceTab("");
        else restoreWorkspace();
      }
      if (event.key === "Escape") setSidebar(false);
    };
    window.addEventListener("keydown", key, true);
    return () => window.removeEventListener("keydown", key, true);
  }, [navigate, selected, project?.id, focused, workspaceTab, terminal]);
  useEffect(() => {
    if (!toast) return;
    const timer = setTimeout(() => setToast(""), 2600);
    return () => clearTimeout(timer);
  }, [toast]);
  useEffect(() => {
    localStorage.setItem("crabase-sidebar-hidden", String(sidebarHidden));
  }, [sidebarHidden]);
  useEffect(() => localStorage.setItem("crabase-details-open", String(details)), [details]);
  useEffect(() => localStorage.setItem("crabase-code-open", String(code)), [code]);
  async function act(action: string, body: unknown) {
    setError("");
    try {
      await request(action, body);
      return true;
    } catch (error) {
      setError((error as Error).message);
      return false;
    }
  }
  async function send(options: SendOptions) {
    const body = draftRef.current.trim();
    const sourcePath = location.pathname;
    if ((!body && !options.attachments?.length) || sending.current) return false;
    sending.current = true;
    setBusy(true);
    setError("");
    try {
      let id = selected || draftRef.chatId;
      if (!id) {
        const result = await request<{ id: string }>("create", {
          project_id: projectId || null,
          title: body.slice(0, 90) || 'Attachments',
        });
        id = result.id;
        draftRef.chatId = id;
      }
      await request("message", {
        ...options,
        chat_id: id,
        body,
        user_id: data.users.find((user) => user.name === preferences.name)?.id,
      });
      if (draftRef.current.trim() === body) clearDraft();
      // A completed send must not pull the reader out of another project.
      if (!selected && location.pathname === sourcePath) navigate(chatPath(id, focused ? scope?.id || projectId : undefined));
      return true;
    } catch (error) {
      setError((error as Error).message);
      return false;
    } finally {
      sending.current = false;
      setBusy(false);
    }
  }
  async function archive(target = chat) {
    if (!target) return;
    if (await act("archive", { chat_id: target.id, archived: !target.archived }))
      setToast(target.archived ? "Chat restored" : "Chat archived");
  }
  async function rename(target: NonNullable<typeof chat>) {
    const title = window.prompt("Rename chat", target.title);
    if (title === null || title.trim() === target.title) return;
    if (await act("rename", { chat_id: target.id, title })) setToast("Chat renamed");
  }
  async function manageProject(target: Project, action: "projectArchive" | "projectDelete") {
    if (action === "projectDelete" && !window.confirm(
      target.parent_id
        ? `Delete worktree "${target.name}" and all its chats? The local folder will be permanently removed, including ignored files such as .env, databases and dependencies. The Git branch will be kept.`
        : `Delete "${target.name}" and all its chats? Delete its worktrees first. The original project folder will be kept.`,
    )) return;
    setError('');
    try {
      const result = await request<{ requires_confirmation?: boolean }>(action, { project_id: target.id, archived: !target.archived });
      if (result.requires_confirmation) {
        if (!window.confirm(`Worktree "${target.name}" has uncommitted or untracked files. Delete anyway? Everything in ${target.path} will be permanently removed. Uncommitted work cannot be recovered from Git. The branch will be kept.`)) return;
        await request(action, { project_id: target.id, force: true });
      }
      setToast(action === "projectDelete" ? "Project deleted" : target.archived ? "Project restored" : "Project archived");
    } catch (error) { setError((error as Error).message); }
  }
  const composer = (
    <Composer
      key={draftKey}
      {...{
        chat,
        project,
        data,
        live,
        loaded,
        draftRef,
        draftVersion,
        busy,
        error,
        request,
        send,
      }}
      typing={workspace.presence.typing.filter((user) => user !== preferences.name)}
      workingFor={chat?.running_by && chat.running_by !== preferences.name ? chat.running_by : undefined}
      dismissError={() => setError("")}
      cancel={() => void act("cancel", { chat_id: selected })}
      restore={() => void archive()}
    />
  );
  const toggleWorkspaceDock = () => updateCurrentWorkspace((current) => {
    const next = current.dock === "bottom" ? "right" : "bottom";
    localStorage.setItem("crabase-workspace-dock", next);
    return { ...current, dock: next };
  });
  const visibleWorkspaceTab = workspaceTab || (currentWorkspace.last === "code" && !project ? "start" : currentWorkspace.last);
  const workspacePanel = selected && chat && loaded && !unavailable && <WorkspacePanel
    active={visibleWorkspaceTab}
    codeAvailable={!!project}
    dock={workspaceDock}
    open={!!workspaceTab}
    seenTabs={seenWorkspaceTabs}
    terminalTabs={workspace.terminals}
    onTab={setWorkspaceTab}
    onSelectTab={selectWorkspace}
    onAddTerminal={() => void openTerminal()}
    onRemoveTab={removeWorkspaceTab}
    onClose={() => setWorkspaceTab("")}
    onDock={toggleWorkspaceDock}
  >
    {project && <div className={`workspace-view ${visibleWorkspaceTab === "code" ? "active" : ""}`}>
      <CodePanel project={project} request={request} theme={preferences.theme}
        fileSearch={fileSearch} closeFileSearch={() => setFileSearch(false)} open={code && !!workspaceTab} close={() => setWorkspaceTab("")} embedded />
    </div>}
    <div className={`workspace-view ${visibleWorkspaceTab === "artifacts" ? "active" : ""}`}>
      <DetailsPanel artifacts={workspace.artifacts} messages={messages} project={project}
        chatSelected={!!selected} loaded={loaded} open={details && !!workspaceTab} close={() => setWorkspaceTab("")} embedded />
    </div>
    {workspace.terminals.map((session) => <div className={`workspace-view ${visibleWorkspaceTab === `terminal:${session.id}` ? "active" : ""}`} key={session.id}>
      <TerminalPanel terminalId={session.id} sessions={workspace.terminals} request={request}
        theme={preferences.theme} open={visibleWorkspaceTab === `terminal:${session.id}` && !!workspaceTab} />
    </div>)}
  </WorkspacePanel>;
  if (route.page === "settings" || user.avatar_required) return <main className="settings-shell">
    <Suspense fallback={<p role="status">Loading settings…</p>}>
      <SettingsPage request={request} projects={data.projects} loaded={loaded} back={backFromSettings} {...preferences} />
    </Suspense>
  </main>;
  return (
    <div className="app-shell">
      <a className="skip-link" href="#main-content">
        Skip to conversation
      </a>
      <Sidebar
        admin={!!user.admin}
        projects={data.projects}
        pins={data.pins}
        pinProject={(target) => void act("projectPin", { project_id: target.id, pinned: !data.pins.includes(target.id) })}
        chats={data.chats}
        selected={selected}
        activeProjectId={project?.id}
        currentProject={focusScope}
        focused={focused}
        overview={showOverview}
        loaded={loaded}
        switchProject={changeProject}
        name={preferences.name}
        avatars={preferences.avatars}
        visible={sidebar}
        hidden={sidebarHidden}
        close={() => {
          setSidebar(false);
          if (window.innerWidth > 760) setSidebarHidden(true);
        }}
        open={open}
        newChat={newChat}
        showDialog={(value) => {
          if (value === "settings") { navigate('/settings'); setSidebar(false); setDialog(''); }
          else setDialog(value);
        }}
        archive={(target) => void archive(target)}
        rename={(target) => void rename(target)}
        manageProject={(target, action) => void manageProject(target, action)}
        createWorktree={setWorktreeProject}
      />
      <main className="main-panel" id="main-content" tabIndex={-1}>
        <Header
          {...{ chat, project, sidebarHidden }}
          viewers={workspace.presence.viewers.filter((user) => user !== preferences.name)}
          avatars={preferences.avatars}
          showSidebar={() => {
            setSidebar(true);
            setSidebarHidden(false);
          }}
          toggleDetails={() => setWorkspace("artifacts")}
          detailsOpen={details}
          toggleCode={() => setWorkspace("code")}
          codeOpen={code}
          toggleTerminal={toggleTerminal}
          terminalOpen={terminal}
          copy={() =>
            void navigator.clipboard
              .writeText(location.href)
              .then(() => setToast("Chat link copied"))
              .catch(() =>
                setError("Unable to copy. Use the address in your browser."),
              )
          }
        />
        <div className="content-layout">
          <div className="main-content">
            {route.page === "missing" || (loaded && (unavailable || ((route.page === "project" || (route.page === "new" && route.projectId)) && !project) || (route.page === "chat" && !chat))) ? (
              <section className="empty-state">
                <h1>{route.page === "project" || route.page === "new" ? "Project unavailable" : route.page === "chat" ? "Chat unavailable" : "Page not found"}</h1>
                {route.page !== "missing" && <p>It may have been removed, or you may no longer have access.</p>}
                <Button variant="secondary" onClick={() => newChat("")}>
                  Back to overview
                </Button>
              </section>
            ) : route.page === "project" && (!loaded || !route.newChat) ? (
              <p className="loading-state" role="status">Opening project…</p>
            ) : route.page === "new" || route.page === "project" ? (
              <NewChatPage hasProject={!!project}>{composer}</NewChatPage>
            ) : (
              <ChatPage
                {...{ chat, messages, approvals, loaded }}
                hasMoreMessages={hasMoreMessages}
                loadingEarlier={loadingEarlier}
                loadEarlier={loadEarlier}
                agentName={data.agentName}
                avatars={preferences.avatars}
                decide={(id, decision) =>
                  void act("approval", {
                    chat_id: selected,
                    approval_id: id,
                    decision,
                  })
                }
                onFileMention={(path) => { selectWorkspace("code"); window.dispatchEvent(new CustomEvent("crabase:reveal-file", { detail: path })); }}
              >
                {composer}
              </ChatPage>
            )}
          </div>
        </div>
        {workspaceDock === "bottom" && workspacePanel}
      </main>
      {workspaceDock === "right" && workspacePanel}
      {toast && (
        <div className="toast" role="status">
          <Check size={16} />
          {toast}
        </div>
      )}
      {dialog === "search" && (
        <Suspense fallback={<p role="status" className="loading-state">Loading search…</p>}>
          <SearchDialog
            chats={data.chats}
            projects={data.projects}
            commands={commands}
            avatars={preferences.avatars}
            open={open}
            close={() => setDialog("")}
          />
        </Suspense>
      )}
      {dialog === "project" && !!user.admin && (
        <Suspense fallback={<p role="status" className="loading-state">Loading projects…</p>}>
          <ProjectDialog
            request={request}
            added={(id) => {
              if (focused) changeProject(id);
              else newChat(id);
              setToast("Project opened");
            }}
            close={() => setDialog("")}
          />
        </Suspense>
      )}
      {worktreeProject && !!user.admin && <Suspense fallback={<p role="status" className="loading-state">Loading worktree…</p>}><WorktreeDialog project={worktreeProject} request={request}
        close={() => setWorktreeProject(null)} added={(id) => { setWorktreeProject(null); newChat(id); setToast('Worktree created'); }} /></Suspense>}
    </div>
  );
}
