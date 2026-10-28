import { useEffect, useMemo, useRef, useState, type RefObject } from "react";
import {
  ArrowUp,
  Folder,
  GitBranch,
  Loader2,
  Paperclip,
  Square,
  StickyNote,
} from "lucide-react";
import type { Chat, Project, ProjectWorkspace, Request, Snapshot } from "../types";
import { IconButton, ErrorNotice, Button, Input } from "./ui";
import { AttachmentList } from './AttachmentList';
import { ModelPicker } from './ModelPicker';
import { reasoningLevels } from '../lib/models';
import { useAttachments } from '../hooks/useAttachments';
import { searchFiles } from '../lib/file-search';
import { fileSearchDirection } from '../lib/shortcuts';
export type SendOptions = {
  mode: "agent" | "note";
  model?: string;
  effort?: string | null;
  attachments?: string[];
};
function typingLabel(names: string[]) {
  if (names.length === 1) return `${names[0]} is typing…`;
  if (names.length === 2) return `${names[0]} and ${names[1]} are typing…`;
  return `${names.length} people are typing…`;
}
export function Composer({
  chat,
  project,
  data,
  live,
  loaded,
  draftRef,
  draftVersion,
  busy,
  error,
  dismissError,
  request,
  send,
  cancel,
  restore,
  typing,
  workingFor,
}: {
  chat?: Chat;
  project?: Project;
  data: Snapshot;
  live: boolean;
  loaded: boolean;
  draftRef: RefObject<string>;
  draftVersion: number;
  busy: boolean;
  error: string;
  dismissError: () => void;
  request: Request;
  send: (options: SendOptions) => Promise<boolean>;
  cancel: () => void;
  restore: () => void;
  typing: string[];
  workingFor?: string;
}) {
  const textarea = useRef<HTMLTextAreaElement>(null);
  const picker = useRef<HTMLInputElement>(null);
  const attachments = useAttachments(request, draftVersion, chat?.id || project?.id || '');
  const [dragging, setDragging] = useState(false);
  const typingSent = useRef(0);
  useEffect(() => {
    typingSent.current = 0;
  }, [chat?.id, live]);
  // The server expires typing after 6 seconds, so refresh it while the draft keeps changing.
  function reportTyping(active: boolean) {
    if (!chat || !live || (active ? Date.now() - typingSent.current < 3000 : !typingSent.current)) return;
    typingSent.current = active ? Date.now() : 0;
    void request("typing", { chat_id: chat.id, active }).catch(() => {});
  }
  const [draft, setDraft] = useState(() => draftRef.current);
  useEffect(() => setDraft(draftRef.current), [draftRef, draftVersion]);
  const [model, setModel] = useState(
    () => sessionStorage.getItem("crabase.model") || "",
  );
  const [effort, setEffort] = useState(
    () => sessionStorage.getItem("crabase.effort") || "",
  );
  const [context, setContext] = useState<{ branch: string | null; path: string; worktree: string | null; detached: boolean } | null>(null);
  const [branchError, setBranchError] = useState(false);
  const [contextVersion, setContextVersion] = useState(0);
  const [modelError, setModelError] = useState("");
  const modelsUnavailable = data.runtime === "error" || (loaded && data.runtime === "connected" && !data.models.length);
  const [filePaths, setFilePaths] = useState<readonly string[]>([]);
  const [filePathsProject, setFilePathsProject] = useState("");
  const [filePathsLoading, setFilePathsLoading] = useState(false);
  const [filePathsError, setFilePathsError] = useState("");
  const filePathsLoadingRef = useRef(false);
  const [fileMention, setFileMention] = useState<{ start: number; end: number; query: string } | null>(null);
  const [fileMentionSelection, setFileMentionSelection] = useState(0);
  const chosen =
    data.models.find((item) => item.model === model) ||
    data.models.find((item) => item.isDefault) ||
    data.models[0];
  const levels = reasoningLevels(chosen);
  const reasoning = levels.some((item) => item.reasoningEffort === effort)
    ? effort
    : levels.find(item => item.reasoningEffort === chosen?.defaultReasoningEffort)?.reasoningEffort || levels[0]?.reasoningEffort || "";
  const disabled = (!draft.trim() && !attachments.files.length) || !attachments.ready || busy || !loaded || !live;
  const fileMentionResults = useMemo(
    () => fileMention ? searchFiles(filePaths, fileMention.query) : [],
    [filePaths, fileMention],
  );
  const activeFileMention = Math.min(fileMentionSelection, Math.max(0, fileMentionResults.length - 1));
  useEffect(() => {
    if (chosen) sessionStorage.setItem("crabase.model", chosen.model);
    sessionStorage.setItem("crabase.effort", reasoning);
  }, [chosen?.model, reasoning]);
  useEffect(() => {
    if (CSS.supports("field-sizing", "content")) return;
    const el = textarea.current;
    if (el) {
      el.style.height = "auto";
      el.style.height = `${Math.min(180, Math.max(52, el.scrollHeight))}px`;
    }
  }, [draft]);
  useEffect(() => {
    setContext(null);
  }, [project?.id, project?.path, live]);
  useEffect(() => {
    setFileMention(null);
    setFilePaths([]);
    setFilePathsProject("");
    setFilePathsError("");
  }, [project?.id]);
  useEffect(() => {
    const projectId = project?.id;
    if (!fileMention || !projectId || !live || filePathsProject === projectId || filePathsLoadingRef.current) return;
    filePathsLoadingRef.current = true;
    setFilePathsLoading(true);
    setFilePathsError("");
    request<ProjectWorkspace>("projectWorkspace", { project_id: projectId })
      .then((workspace) => {
        setFilePaths(workspace.paths);
        setFilePathsProject(projectId);
      })
      .catch((error) => {
        setFilePathsError((error as Error).message);
        setFilePathsProject(projectId);
      })
      .finally(() => {
        filePathsLoadingRef.current = false;
        setFilePathsLoading(false);
      });
  }, [fileMention, project?.id, live, filePathsProject, request]);
  useEffect(() => {
    setFileMentionSelection(0);
  }, [fileMention?.query]);
  useEffect(() => {
    const selected = fileMentionResults[activeFileMention];
    if (!selected) return;
    document.getElementById(`file-mention-${activeFileMention}`)?.scrollIntoView({ block: "nearest" });
  }, [activeFileMention, fileMentionResults]);
  useEffect(() => {
    setBranchError(false);
    if (!project || !live) return;
    let stale = false;
    request<NonNullable<typeof context>>("projectContext", {
      project_id: project.id,
    })
      .then((result) => {
        if (!stale) setContext(result);
      })
      .catch(() => {
        if (!stale) setBranchError(true);
      });
    return () => {
      stale = true;
    };
  }, [chat?.id, chat?.status, project?.id, project?.path, live, request, contextVersion]);
  useEffect(() => {
    const refresh = () => setContextVersion(version => version + 1);
    window.addEventListener('focus', refresh);
    return () => window.removeEventListener('focus', refresh);
  }, []);
  function submit(mode: "agent" | "note") {
    if (disabled || (mode === "agent" && !chosen)) return;
    void send(
      mode === "note"
        ? { mode, attachments: attachments.ids() }
        : { mode, model: chosen.model, effort: reasoning || null, attachments: attachments.ids() },
    ).then((sent) => { if (sent) { attachments.clear(); reportTyping(false); } textarea.current?.focus(); });
  }
  function updateFileMention(value: string, caret: number | null) {
    if (!project || !live || caret === null) {
      setFileMention(null);
      return;
    }
    const beforeCaret = value.slice(0, caret);
    const at = beforeCaret.lastIndexOf("@");
    if (at < 0 || (at > 0 && /[\w@]/.test(beforeCaret[at - 1]))) {
      setFileMention(null);
      return;
    }
    const query = beforeCaret.slice(at + 1);
    if (/[\s\n\r]/.test(query)) {
      setFileMention(null);
      return;
    }
    setFileMention({ start: at, end: caret, query });
  }
  function chooseFileMention(path: string) {
    if (!fileMention) return;
    const value = draftRef.current;
    const insertion = `@${path} `;
    const next = value.slice(0, fileMention.start) + insertion + value.slice(fileMention.end);
    const caret = fileMention.start + insertion.length;
    draftRef.current = next;
    setDraft(next);
    setFileMention(null);
    requestAnimationFrame(() => {
      textarea.current?.focus();
      textarea.current?.setSelectionRange(caret, caret);
    });
  }
  function retryFilePaths() {
    setFilePathsProject("");
    setFilePathsError("");
  }
  return (
    <div className={`composer-wrap ${chat ? "in-chat" : ""}`}>
      <ErrorNotice message={error} dismiss={dismissError} />
      {!live && (
        <p className="connection-notice" role="status">
          Reconnecting… Your draft is kept here.
        </p>
      )}
      {!chat && project && (
        <div className="composer-project-bar">
          <span>
            <Folder size={15} />
            {project.name}
          </span>
        </div>
      )}
      {chat && !chat.archived && (
        <p className="composer-status">
          {workingFor && <span role="status">{data.agentName} is working on {workingFor}’s request. A message sent now steers that work.</span>}
          {typing.length > 0 && <span>{typingLabel(typing)}</span>}
        </p>
      )}
      {chat?.archived ? (
        <div className="archived-notice">
          <span>This chat is archived.</span>
          <Button variant="secondary" onClick={restore}>
            Restore chat
          </Button>
        </div>
      ) : (
        <div className={`composer-box ${dragging ? 'attachment-dragging' : ''}`}
          onDragOver={event => { if (event.dataTransfer.types.includes('Files')) { event.preventDefault(); setDragging(true); } }}
          onDragLeave={event => { if (!event.currentTarget.contains(event.relatedTarget as Node)) setDragging(false); }}
          onDrop={event => { event.preventDefault(); setDragging(false); if (!busy && live) attachments.add(Array.from(event.dataTransfer.files)); }}
          onPaste={event => { if (event.clipboardData.files.length && !busy && live) { event.preventDefault(); attachments.add(Array.from(event.clipboardData.files)); } }}>
          <AttachmentList files={attachments.files} remove={attachments.remove} retry={attachments.retry} disabled={busy || !live} />
          <ErrorNotice message={attachments.error} />
          <textarea
            ref={textarea}
            aria-label={`Message ${data.agentName}`}
            aria-autocomplete="list"
            aria-expanded={!!fileMention}
            aria-controls={fileMention ? "file-mention-list" : undefined}
            aria-activedescendant={fileMention && fileMentionResults.length ? `file-mention-${activeFileMention}` : undefined}
            placeholder={`Message ${data.agentName}…`}
            rows={2}
            value={draft}
            maxLength={20000}
            onChange={(event) => {
              draftRef.current = event.target.value;
              setDraft(event.target.value);
              reportTyping(!!event.target.value.trim());
              updateFileMention(event.target.value, event.target.selectionStart);
            }}
            onClick={(event) => updateFileMention(event.currentTarget.value, event.currentTarget.selectionStart)}
            onKeyUp={(event) => {
              if (["Enter", "Tab", "Escape", "ArrowDown", "ArrowUp"].includes(event.key)) return;
              updateFileMention(event.currentTarget.value, event.currentTarget.selectionStart);
            }}
            onKeyDown={(event) => {
              if (event.nativeEvent.isComposing) return;
              if (fileMention) {
                const direction = fileSearchDirection(event.nativeEvent);
                if (direction) {
                  event.preventDefault();
                  if (fileMentionResults.length) setFileMentionSelection((selection) => (selection + direction + fileMentionResults.length) % fileMentionResults.length);
                  return;
                }
                if (!event.shiftKey && (event.key === "Enter" || event.key === "Tab") && fileMentionResults[activeFileMention]) {
                  event.preventDefault();
                  chooseFileMention(fileMentionResults[activeFileMention]);
                  return;
                }
                if (event.key === "Escape") {
                  event.preventDefault();
                  setFileMention(null);
                  return;
                }
                if (event.key === "Enter" && !event.shiftKey && filePathsLoading) {
                  event.preventDefault();
                  return;
                }
              }
              if (
                event.key === "Enter" &&
                !event.shiftKey &&
                !event.nativeEvent.isComposing
              ) {
                event.preventDefault();
                submit("agent");
              }
            }}
            onBlur={(event) => {
              const target = event.relatedTarget as HTMLElement | null;
              if (!target?.closest(".file-mention-menu")) setFileMention(null);
            }}
          />
          {fileMention && (
            <div id="file-mention-list" className="file-mention-menu" role="listbox" aria-label="Mention a project file">
              {fileMentionResults.map((path, index) => (
                <button
                  key={path}
                  id={`file-mention-${index}`}
                  type="button"
                  role="option"
                  aria-selected={index === activeFileMention}
                  onMouseDown={(event) => event.preventDefault()}
                  onMouseEnter={() => setFileMentionSelection(index)}
                  onClick={() => chooseFileMention(path)}
                  title={path}
                >
                  <span className="file-mention-icon" aria-hidden="true">@</span>
                  <span className="truncate">{path}</span>
                </button>
              ))}
              {!fileMentionResults.length && (filePathsError ? (
                <div className="file-mention-status" role="status">
                  <span>{filePathsError}</span>
                  <button type="button" onMouseDown={(event) => event.preventDefault()} onClick={retryFilePaths}>Retry</button>
                </div>
              ) : (
                <p className="file-mention-status" role="status">
                  {filePathsLoading ? "Loading project files…" : "No matching files."}
                </p>
              ))}
              {fileMentionResults.length > 0 && <span className="file-mention-hint"><kbd>↑</kbd><kbd>↓</kbd> to move <kbd>Enter</kbd> to mention</span>}
            </div>
          )}
          <div className="composer-controls">
            <div className="model-controls">
              <Input ref={picker} type="file" multiple hidden onChange={event => { attachments.add(Array.from(event.target.files || [])); event.target.value = ''; }} />
              <IconButton label="Attach files" disabled={busy || !live || attachments.files.length >= 10} onClick={() => picker.current?.click()}><Paperclip size={17} /></IconButton>
              <ModelPicker models={data.models} chosen={chosen} reasoning={reasoning}
                unavailable={modelsUnavailable}
                selectModel={model => { setModel(model); setEffort(''); }}
                selectReasoning={setEffort} />
              {context?.branch && (
                <span className="composer-branch" tabIndex={0}
                  title={`${context.detached ? 'Detached HEAD: ' : 'Branch: '}${context.branch}\n${context.worktree ? `Worktree: ${context.worktree}\n` : ''}${context.path}`}
                  onMouseEnter={() => setContextVersion(version => version + 1)}
                  onFocus={() => setContextVersion(version => version + 1)}>
                  <GitBranch size={14} aria-hidden="true" />
                  <span className="truncate">{context.detached ? 'HEAD · ' : ''}{context.branch}</span>
                </span>
              )}
              {project && branchError && <Button variant="text" onClick={() => setContextVersion(version => version + 1)}>Retry branch lookup</Button>}
              {modelsUnavailable && (
                <Button
                  variant="text"
                  onClick={() => {
                    setModelError("");
                    void request("models").catch((error: Error) =>
                      setModelError(error.message),
                    );
                  }}
                >
                  Retry models
                </Button>
              )}
            </div>
            <div className="send-controls">
              <IconButton
                label="Send note"
                disabled={disabled}
                onClick={() => submit("note")}
              >
                <StickyNote size={18} />
              </IconButton>
              {chat && chat.status !== "idle" ? (
                <IconButton
                  className="send-button"
                  label="Stop agent and queued requests"
                  disabled={!live}
                  onClick={cancel}
                >
                  <Square size={16} />
                </IconButton>
              ) : (
                <IconButton
                  className="send-button"
                  label={`Send to ${data.agentName}`}
                  disabled={disabled || !chosen}
                  onClick={() => submit("agent")}
                >
                  {busy ? (
                    <Loader2 size={18} className="spin" />
                  ) : (
                    <ArrowUp size={19} />
                  )}
                </IconButton>
              )}
            </div>
          </div>
          <ErrorNotice message={modelError} />
        </div>
      )}
    </div>
  );
}
