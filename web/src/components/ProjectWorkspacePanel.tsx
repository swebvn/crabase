import { lazy, Suspense, useEffect, useLayoutEffect, useRef, useState, type CSSProperties, type ReactNode } from "react";
import { Columns2, Rows2, FilePlus2, Files as FilesIcon, GitCompareArrows, RefreshCw, X } from "lucide-react";
import { FileTree, useFileTree } from "@pierre/trees/react";
import type { Project, ProjectWorkspace, Request, WorkspaceChange } from "../types";
import { IconButton } from "./ui";
import { FilePalette } from "./FilePalette";
import { clampPanelWidth } from "../lib/layout";
const WorkspaceCode = lazy(() => import("./WorkspaceCode"));
const WorkspaceEditor = lazy(() => import("./WorkspaceEditor"));

type OpenFile = { path: string; contents: string; hash: string; draft: string; image?: string; unsupported?: string };
type Diff = { path: string; patch: string };

export function ProjectWorkspacePanel({ project, request, theme, actions, fileSearch, closeFileSearch }: {
  project: Project;
  request: Request;
  theme: string;
  actions: ReactNode;
  fileSearch: boolean;
  closeFileSearch: () => void;
}) {
  const [workspace, setWorkspace] = useState<ProjectWorkspace>();
  const [fileRequest, setFileRequest] = useState<{ path: string; token: number }>();
  const pendingReveal = useRef<string | undefined>(undefined);
  const previousWorkspace = useRef<ProjectWorkspace | undefined>(undefined);
  const [files, setFiles] = useState<OpenFile[]>([]);
  const [activePath, setActivePath] = useState("");
  const [selectedChange, setSelectedChange] = useState<WorkspaceChange>();
  const [diff, setDiff] = useState<Diff>();
  const [navigator, setNavigator] = useState<"files" | "changes">("files");
  const [surface, setSurface] = useState<"file" | "diff">("file");
  const [diffStyle, setDiffStyle] = useState<"unified" | "split">("unified");
  const [navigatorWidth, setNavigatorWidth] = useState(() =>
    clampPanelWidth(Number(localStorage.getItem("crabase-code-navigator-width")) || 280, 200, 500));
  const splitRef = useRef<HTMLDivElement>(null);
  const dragStart = useRef({ x: 0, width: 280 });
  useEffect(() => localStorage.setItem("crabase-code-navigator-width", String(navigatorWidth)), [navigatorWidth]);
  useEffect(() => {
    const reveal = (event: Event) => {
      const path = (event as CustomEvent<string>).detail;
      if (workspace) setFileRequest({ path, token: Date.now() });
      else pendingReveal.current = path;
    };
    window.addEventListener("crabase:reveal-file", reveal);
    return () => window.removeEventListener("crabase:reveal-file", reveal);
  }, [workspace]);
  useEffect(() => {
    if (workspace && pendingReveal.current) {
      const path = pendingReveal.current;
      pendingReveal.current = undefined;
      setFileRequest({ path, token: Date.now() });
    }
  }, [workspace]);

  function resizeNavigator(width: number) {
    setNavigatorWidth(clampPanelWidth(width, 200, 500, splitRef.current?.clientWidth || 700));
  }
  const [refreshToken, setRefreshToken] = useState(0);
  const [workspaceLoading, setWorkspaceLoading] = useState(true);
  const [fileLoading, setFileLoading] = useState(false);
  const [diffLoading, setDiffLoading] = useState(false);
  const [saving, setSaving] = useState(0);
  const pendingSaves = useRef(new Map<string, Promise<string>>());
  const loading = workspaceLoading || fileLoading || diffLoading || saving > 0;
  const [error, setError] = useState("");
  const active = files.find((file) => file.path === activePath);
  const { model } = useFileTree({
    paths: [],
    density: "compact",
    icons: { set: "complete", colored: false },
    initialExpansion: 0,
    search: false,
    flattenEmptyDirectories: true,
    onSelectionChange: (paths) => openFile(paths.at(-1) || ""),
  });

  function openFile(path: string) {
    if (!path || path.endsWith("/")) return;
    setSurface("file");
    setFileRequest((current) => ({ path, token: (current?.token || 0) + 1 }));
  }

  function selectFile(path: string) {
    setActivePath(path);
    setSurface("file");
  }

  function refresh() {
    setRefreshToken((token) => token + 1);
  }

  useEffect(() => {
    let stale = false;
    setWorkspaceLoading(true);
    setError("");
    request<ProjectWorkspace>("projectWorkspace", { project_id: project.id })
      .then((result) => { if (!stale) setWorkspace(result); })
      .catch((error) => { if (!stale) setError((error as Error).message); })
      .finally(() => { if (!stale) setWorkspaceLoading(false); });
    return () => { stale = true; };
  }, [project.id, request, refreshToken]);

  useEffect(() => {
    const paths = workspace?.paths || [];
    const expanded = new Set<string>();
    if (previousWorkspace.current) {
      for (const path of previousWorkspace.current.paths) {
        const item = model.getItem(path);
        if (item && "isExpanded" in item && item.isExpanded()) expanded.add(path);
      }
    }
    model.resetPaths(paths);
    if (previousWorkspace.current) {
      for (const path of paths) {
        const item = model.getItem(path);
        if (item && "isExpanded" in item && item.isExpanded() !== expanded.has(path)) item.toggle();
      }
    }
    if (workspace) previousWorkspace.current = workspace;
    model.setGitStatus([
      ...(workspace?.changes || []),
      ...(workspace?.ignored || []).map(path => ({ path, status: "ignored" as const })),
    ]);
  }, [model, workspace]);

  useEffect(() => {
    setFileLoading(false);
    if (!fileRequest) return;
    let stale = false;
    const existing = files.find((file) => file.path === fileRequest.path);
    if (existing) {
      selectFile(existing.path);
      return;
    }
    setFileLoading(true);
    setError("");
    request<Omit<OpenFile, "draft">>("projectFile", {
      project_id: project.id,
      path: fileRequest.path,
    }).then((file) => {
      if (stale) return;
      setFiles((current) => current.some((item) => item.path === file.path)
        ? current
        : [...current, { ...file, draft: file.contents }]);
      selectFile(file.path);
    }).catch((error) => { if (!stale) setError((error as Error).message); })
      .finally(() => { if (!stale) setFileLoading(false); });
    return () => { stale = true; };
  }, [fileRequest, project.id, request]);

  useEffect(() => {
    setDiffLoading(false);
    if (!selectedChange) return;
    let stale = false;
    setDiff(undefined);
    setDiffLoading(true);
    setError("");
    request<Diff>("projectDiff", { project_id: project.id, path: selectedChange.path })
      .then((result) => { if (!stale) setDiff(result); })
      .catch((error) => { if (!stale) setError((error as Error).message); })
      .finally(() => { if (!stale) setDiffLoading(false); });
    return () => { stale = true; };
  }, [project.id, request, selectedChange]);

  function updateFile(path: string, update: (file: OpenFile) => OpenFile) {
    setFiles((current) => current.map((file) => file.path === path ? update(file) : file));
  }

  async function save(file: OpenFile) {
    if (file.draft === file.contents && !pendingSaves.current.has(file.path)) return;
    setSaving((count) => count + 1);
    setError("");
    // Each save uses the version returned by the preceding save of this file.
    const pending = (pendingSaves.current.get(file.path) || Promise.resolve(file.hash))
      .then(async (hash) => {
        const result = await request<{ hash: string }>("projectSave", {
          project_id: project.id, path: file.path, contents: file.draft, hash,
        });
        updateFile(file.path, (current) => ({ ...current, contents: file.draft, hash: result.hash }));
        return result.hash;
      });
    pendingSaves.current.set(file.path, pending);
    try {
      await pending;
      refresh();
    } catch (error) {
      setError((error as Error).message);
    } finally {
      if (pendingSaves.current.get(file.path) === pending) pendingSaves.current.delete(file.path);
      setSaving((count) => count - 1);
    }
  }

  function closeFile(file: OpenFile) {
    if (pendingSaves.current.has(file.path)) return;
    if (file.draft !== file.contents && !window.confirm(`Discard unsaved changes to ${file.path}?`)) return;
    const remaining = files.filter((item) => item.path !== file.path);
    setFiles(remaining);
    setFileRequest(undefined);
    if (activePath === file.path) setActivePath(remaining.at(-1)?.path || "");
  }

  function openChange(change: WorkspaceChange) {
    setFileRequest(undefined);
    setSelectedChange(change);
    setSurface("diff");
  }
  function closeDiff() {
    setSelectedChange(undefined);
    setDiff(undefined);
    setSurface("file");
  }

  return <section className="workspace-browser">
    {fileSearch && <FilePalette paths={workspace?.paths || []} loading={workspaceLoading} error={error}
      open={openFile} close={closeFileSearch} />}
    {error && <p className="workspace-message error-notice" role="alert">{error}</p>}
    <div className="workspace-split" ref={splitRef}
      style={{ "--navigator-width": `${navigatorWidth}px` } as CSSProperties}>
      <aside className="workspace-navigator" aria-label={navigator === "files" ? "Project files" : "Git changes"}>
        <nav className="workspace-view-switch" aria-label="Code navigator">
          <IconButton label="Files" aria-pressed={navigator === "files"} onClick={() => setNavigator("files")}>
            <FilesIcon size={15} />
          </IconButton>
          <IconButton label={`Changes (${workspace?.changes.length || 0})`} aria-pressed={navigator === "changes"} onClick={() => setNavigator("changes")}>
            <GitCompareArrows size={15} />
          </IconButton>
          <IconButton label="Refresh project" onClick={refresh} disabled={loading}>
            <RefreshCw size={15} className={loading ? "spin" : ""} />
          </IconButton>
          {actions}
        </nav>
        <div className="workspace-navigator-resize" role="separator" aria-label="Resize code navigator"
          aria-orientation="vertical" aria-valuemin={200} aria-valuemax={500} aria-valuenow={navigatorWidth}
          tabIndex={0} onPointerDown={(event) => {
            if (event.button !== 0) return;
            event.preventDefault();
            dragStart.current = { x: event.clientX, width: navigatorWidth };
            event.currentTarget.setPointerCapture(event.pointerId);
          }} onPointerMove={(event) => {
            if (event.currentTarget.hasPointerCapture(event.pointerId))
              resizeNavigator(dragStart.current.width + dragStart.current.x - event.clientX);
          }} onPointerUp={(event) => {
            if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId);
          }} onKeyDown={(event) => {
            if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
            event.preventDefault();
            resizeNavigator(event.key === "Home" ? 200 : event.key === "End" ? 500
              : navigatorWidth + (event.key === "ArrowLeft" ? 16 : -16));
          }} />
        {!workspace ? <p className="workspace-message muted">{workspaceLoading ? "Loading project…" : "Unable to load project. Try refreshing."}</p>
          : navigator === "files" ? workspace.paths.length ? (
            <FileTree model={model} className="project-file-tree" onClick={(event) => {
              const row = event.nativeEvent.composedPath()
                .find((node) => node instanceof HTMLElement && node.dataset.itemType === "file");
              if (row instanceof HTMLElement && row.dataset.itemPath) openFile(row.dataset.itemPath);
            }} />
          ) : <p className="workspace-message muted">No files found.</p>
          : !workspace.git ? <p className="workspace-message muted">This project is not a Git repository.</p>
          : workspace.changes.length ? <ul className="workspace-changes">{workspace.changes.map((change) => (
            <li key={`${change.code}:${change.path}`}><button className={selectedChange?.path === change.path ? "selected" : ""}
              onClick={() => openChange(change)} title={change.path}>
              <span className={`change-status ${change.status}`} aria-hidden="true">{statusLabel(change.status)}</span>
              <span className="truncate">{change.path}</span>
            </button></li>
          ))}</ul> : <p className="workspace-message muted">No changes.</p>}
      </aside>
      {(files.length > 0 || selectedChange || fileLoading) && <div className="workspace-stage"
        onKeyDownCapture={(event) => {
          if (!event.metaKey || event.ctrlKey || event.altKey || event.shiftKey ||
            event.nativeEvent.isComposing || event.key.toLowerCase() !== "w") return;
          if (surface === "diff" ? !selectedChange : !active) return;
          event.preventDefault();
          event.stopPropagation();
          if (event.repeat) return;
          if (surface === "diff") closeDiff();
          else if (active) closeFile(active);
        }}>
        <div className="workspace-stage-header">
          <FileTabs files={files} active={activePath} diff={selectedChange} surface={surface}
            select={openFile} close={closeFile} saving={pendingSaves.current}
            selectDiff={() => { if (selectedChange) openChange(selectedChange); }} closeDiff={closeDiff} />
          {surface === "diff" && diff && <div className="workspace-stage-actions">
            <IconButton label="Unified diff" aria-pressed={diffStyle === "unified"}
              className={diffStyle === "unified" ? "active" : ""} onClick={() => setDiffStyle("unified")}>
              <Rows2 size={16} />
            </IconButton>
            <IconButton label="Side-by-side diff" aria-pressed={diffStyle === "split"}
              className={diffStyle === "split" ? "active" : ""} onClick={() => setDiffStyle("split")}>
              <Columns2 size={16} />
            </IconButton>
            <button className="button secondary workspace-action"
              disabled={selectedChange?.status === "deleted"} onClick={() => openFile(diff.path)}>Edit file</button>
          </div>}
        </div>
        {surface === "file" ? active ? active.unsupported ? <div className="workspace-empty" role="status">
          <span>{active.unsupported}</span>
        </div> : active.image ? <div className="workspace-image-preview">
          <img src={active.image} alt={active.path} onError={() => setError(`Unable to display ${active.path}.`)} />
        </div> : <>
          <div className="workspace-code"><Suspense fallback={<p className="workspace-message muted">Loading editor…</p>}>
            <WorkspaceEditor key={`${project.id}:${active.path}`} path={active.path} value={active.draft} theme={theme}
              change={(draft) => updateFile(active.path, (file) => ({ ...file, draft }))}
              save={() => void save(active)} />
          </Suspense></div>
        </> : <div className="workspace-empty"><FilePlus2 size={20} /><span>Select a file to edit.</span></div>
        : diff ? <>
          <div className="workspace-code"><Suspense fallback={<p className="workspace-message muted">Loading diff…</p>}>
            <WorkspaceCode diff={diff} theme={theme} diffStyle={diffStyle} />
          </Suspense></div>
        </> : <div className="workspace-empty"><span>{loading ? "Loading change…" : "Select a change to review."}</span></div>}
      </div>}
    </div>
  </section>;
}

function FileTabs({ files, active, diff, surface, select, close, saving, selectDiff, closeDiff }: {
  files: OpenFile[];
  active: string;
  diff?: WorkspaceChange;
  surface: "file" | "diff";
  select: (path: string) => void;
  close: (file: OpenFile) => void;
  saving: Map<string, Promise<string>>;
  selectDiff: () => void;
  closeDiff: () => void;
}) {
  const tabsRef = useRef<HTMLElement>(null);
  useLayoutEffect(() => {
    const strip = tabsRef.current;
    const tab = strip?.querySelector<HTMLElement>('[data-active="true"]');
    if (!strip || !tab) return;
    const reveal = () => {
      const viewport = strip.getBoundingClientRect();
      const bounds = tab.getBoundingClientRect();
      if (bounds.left < viewport.left) strip.scrollLeft += bounds.left - viewport.left;
      else if (bounds.right > viewport.right) strip.scrollLeft += bounds.right - viewport.right;
    };
    reveal();
    const observer = new ResizeObserver(reveal);
    observer.observe(strip);
    observer.observe(tab);
    return () => observer.disconnect();
  }, [active, surface, diff?.path, files.length]);

  return <nav ref={tabsRef} className="workspace-editor-tabs" aria-label="Open files">
    {files.map((file) => <div className="workspace-editor-tab" data-active={surface === "file" && file.path === active} key={file.path}>
      <button onClick={() => select(file.path)} title={file.path}>
        <span className="truncate">{file.path.split("/").pop()}</span>
        {file.draft !== file.contents && <span className="workspace-unsaved" role="img" aria-label="Unsaved changes" />}
      </button>
      <IconButton label={`Close ${file.path}`} onClick={() => close(file)} disabled={saving.has(file.path)}><X size={13} /></IconButton>
    </div>)}
    {diff && <div className="workspace-editor-tab diff" data-active={surface === "diff"}>
      <button onClick={selectDiff} title={`Review ${diff.path}`}>
        <GitCompareArrows size={13} /><span className="truncate">{diff.path.split("/").pop()}</span>
      </button>
      <IconButton label={`Close review of ${diff.path}`} onClick={closeDiff}><X size={13} /></IconButton>
    </div>}
  </nav>;
}

function statusLabel(status: WorkspaceChange["status"]) {
  return status === "modified" ? "M" : status === "deleted" ? "D" : status === "renamed" ? "R" : status === "untracked" ? "U" : "A";
}
