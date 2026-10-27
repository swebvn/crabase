import { useEffect, useRef, useState, type CSSProperties } from "react";
import {
  GitBranch,
  Loader2,
  MoreHorizontal,
  PanelLeft,
  Plus,
  Search,
  Settings,
  SquarePen,
} from "lucide-react";
import type { Avatars, Chat, Project } from "../types";
import { Avatar, AvatarStack } from "./Avatar";
import { IconButton, Menu, MenuItem } from "./ui";
import { useProjectExpansion } from "../hooks/useProjectExpansion";
import { clampSidebarWidth } from "../lib/layout";
import { sortProjects } from "../lib/projects";
import { ProjectSwitcher } from "./ProjectSwitcher";
const widthStorageKey = "crabase-sidebar-width";
const modifierKey = /Mac|iPhone|iPad/.test(navigator.platform) ? "⌘" : "Ctrl";

type Props = {
  projects: Project[];
  pins: string[];
  pinProject: (project: Project) => void;
  admin: boolean;
  chats: Chat[];
  selected: string;
  activeProjectId?: string;
  currentProject?: Project;
  loaded: boolean;
  switchProject: (id: string) => void;
  name: string;
  avatars: Avatars;
  visible: boolean;
  hidden: boolean;
  close: () => void;
  open: (id: string) => void;
  newChat: (project?: string) => void;
  showDialog: (dialog: "search" | "project" | "settings") => void;
  archive: (chat: Chat) => void;
  rename: (chat: Chat) => void;
  manageProject: (project: Project, action: "projectArchive" | "projectDelete") => void;
  createWorktree: (project: Project) => void;
};
function ChatLink({
  chat,
  selected,
  avatars,
  open,
  archive,
  rename,
}: {
  chat: Chat;
  selected: string;
  avatars: Avatars;
  open: (id: string) => void;
  archive: (chat: Chat) => void;
  rename: (chat: Chat) => void;
}) {
  return (
    <div
      className={`chat-row ${chat.status !== "idle" ? "has-progress" : ""}`}
    >
      <button
        className={`chat-link ${selected === chat.id ? "selected" : ""}`}
        aria-current={selected === chat.id ? "page" : undefined}
        onClick={() => open(chat.id)}
        onMouseEnter={(event) => {
          const title = event.currentTarget.querySelector<HTMLElement>(
            ".chat-title-text",
          )!;
          const text = title.firstElementChild as HTMLElement;
          title.toggleAttribute(
            "data-overflow",
            text.offsetWidth > title.parentElement!.clientWidth,
          );
          const distance = title.offsetWidth;
          title.style.setProperty(
            "--marquee-distance",
            `${-distance}px`,
          );
          title.style.setProperty(
            "--marquee-duration",
            `${distance / 40}s`,
          );
        }}
        title={chat.title}
      >
        <AvatarStack users={chat.participants} avatars={avatars} />
        <span className="chat-title">
          <span className="chat-title-text">
            <span>{chat.title}</span>
            <span className="chat-title-repeat" aria-hidden="true">{chat.title}</span>
          </span>
        </span>
      </button>
      <div className="chat-trailing">
        {chat.status !== "idle" && (
          <Loader2
            size={13}
            className="spin chat-progress"
            aria-label={chat.status}
          />
        )}
        <Menu
          viewport
          className="chat-menu"
          label={`Options for ${chat.title}`}
          icon={<MoreHorizontal size={15} />}
        >
          <MenuItem onClick={() => rename(chat)}>Rename</MenuItem>
          <MenuItem onClick={() => archive(chat)}>Archive</MenuItem>
        </Menu>
      </div>
    </div>
  );
}
export function Sidebar({
  projects,
  pins,
  pinProject,
  admin,
  chats,
  selected,
  name,
  activeProjectId,
  currentProject,
  loaded,
  switchProject,
  avatars,
  visible,
  hidden,
  close,
  open,
  newChat,
  showDialog,
  archive,
  rename,
  manageProject,
  createWorktree,
}: Props) {
  const [showArchived, setShowArchived] = useState(false);
  const [width, setWidth] = useState(() => clampSidebarWidth(Number(localStorage.getItem(widthStorageKey)) || 232));
  const [resizing, setResizing] = useState(false);
  useEffect(() => localStorage.setItem(widthStorageKey, String(width)), [width]);
  const [showAll, setShowAll] = useState<string[]>([]);
  const dragOffset = useRef(0);
  const { collapsed, toggleProject } = useProjectExpansion();
  useEffect(() => {
    if (activeProjectId && collapsed.includes(activeProjectId)) toggleProject(activeProjectId);
  }, [activeProjectId, selected]);
  const directChats = chats.filter(chat => (currentProject ? chat.project_id === currentProject.id : !chat.project_id) && (!chat.archived || chat.id === selected));
  const worktrees = currentProject ? sortProjects(projects.filter(child => child.parent_id === currentProject.id && (showArchived || !child.archived || child.id === activeProjectId)), chats, "updated") : [];
  const projectMenu = (project: Project) => <Menu viewport label={`Project options for ${project.name}`} icon={<MoreHorizontal size={16} />}>
    {!project.parent_id && <MenuItem onClick={() => pinProject(project)}>{pins.includes(project.id) ? "Unpin" : "Pin"}</MenuItem>}
    {admin && !project.parent_id && !project.archived && <MenuItem onClick={() => createWorktree(project)}>Create worktree</MenuItem>}
    {!project.parent_id && <MenuItem selected={showArchived} onClick={() => setShowArchived(value => !value)}>Show archived worktrees</MenuItem>}
    {admin && <><MenuItem onClick={() => manageProject(project, "projectArchive")}>{project.archived ? "Restore" : "Archive"}</MenuItem>
      <MenuItem onClick={() => manageProject(project, "projectDelete")}>Delete</MenuItem></>}
  </Menu>;
  const renderWorktree = (project: Project) => {
    const expanded = !collapsed.includes(project.id);
    const projectChats = chats.filter(
      (chat) => chat.project_id === project.id && (!chat.archived || chat.id === selected),
    );
    const shownChats = showAll.includes(project.id)
      ? projectChats
      : projectChats.filter((chat, index) => index < 5 || chat.id === selected);
    const busy = !expanded && chats.some((chat) => !chat.archived && chat.status !== "idle" && (chat.project_id === project.id
      || projects.some((child) => child.id === chat.project_id && child.parent_id === project.id)));
    return (
      <section
        className="project-group worktree-group"
        key={project.id}
        aria-label={project.name}
      >
        <div className="project-row">
          <button
            className="project-label"
            aria-expanded={expanded}
            aria-controls={`project-${project.id}`}
            onClick={() => toggleProject(project.id)}
          >
            <GitBranch size={16} aria-hidden="true" />
            <span className="truncate" title={project.parent_id ? `${project.name}\n${project.path}` : project.name}>{project.name}{project.archived ? " · Archived" : ""}</span>
            {busy && <Loader2 size={13} className="spin" aria-label="Agent working" />}
          </button>
          <div className="project-actions">
          {admin && projectMenu(project)}
          <IconButton
            label={`New chat in ${project.name}`}
            onClick={() => newChat(project.id)}
          >
            <Plus size={13} />
          </IconButton>
          </div>
        </div>
        {expanded && (
          <div
            id={`project-${project.id}`}
            className="project-chats"
          >
            {shownChats.map((chat) => (
              <ChatLink
                key={chat.id}
                {...{ chat, selected, avatars, open, archive, rename }}
              />
            ))}
            {projectChats.length > shownChats.length && (
              <button
                className="load-more"
                onClick={() =>
                  setShowAll((ids) => [...ids, project.id])
                }
              >
                Load more ({projectChats.length - shownChats.length})
              </button>
            )}
          </div>
        )}
      </section>
    );
  };
  return (
    <>
      <button
        className={`sidebar-scrim ${visible ? "show" : ""}`}
        aria-label="Close sidebar"
        tabIndex={visible ? 0 : -1}
        onClick={close}
      />
      <aside
        className={`sidebar ${visible ? "mobile-open" : ""} ${hidden ? "desktop-hidden" : ""} ${resizing ? "resizing" : ""}`}
        aria-label="Workspace sidebar"
        id="workspace-sidebar"
        style={{ "--sidebar-width": `${width}px` } as CSSProperties}
      >
        <div className="sidebar-heading">
          <ProjectSwitcher projects={projects} chats={chats} pins={pins} current={currentProject} loaded={loaded} admin={admin}
            select={switchProject} add={() => showDialog("project")} />
          <IconButton label="Close sidebar" onClick={close}>
            <PanelLeft size={17} />
          </IconButton>
        </div>
        <nav aria-label="Workspace" className="primary-nav">
          <button
            className={`nav-item ${!selected ? "selected" : ""}`}
            onClick={() => newChat(currentProject?.id)}
          >
            <SquarePen size={17} />
            <span>New chat</span>
            <kbd>{modifierKey} ⇧ O</kbd>
          </button>
        </nav>
        <div className="sidebar-scroll">
          <div className="section-label">
            <span>{currentProject?.archived ? "Chats · Archived project" : "Chats"}</span>
            {currentProject && projectMenu(currentProject)}
          </div>
          {!loaded && !chats.length && <p className="sidebar-empty" role="status">Loading chats…</p>}
          {directChats.map(chat => <ChatLink key={chat.id} {...{ chat, selected, avatars, open, archive, rename }} />)}
          {loaded && !directChats.length && <p className="sidebar-empty">No chats yet.</p>}
          {!!worktrees.length && <div className="section-label">Worktrees</div>}
          {worktrees.map(renderWorktree)}
        </div>
        <div className="profile-row">
          <button
            className="profile-button"
            onClick={() => showDialog("settings")}
          >
            <Avatar user={name} avatars={avatars} />
            <span className="truncate" title={name}>{name}</span>
          </button>
          <IconButton label={`Search chats and projects (${modifierKey}+K)`} onClick={() => showDialog("search")}>
            <Search size={17} />
          </IconButton>
          <IconButton label="Settings" onClick={() => showDialog("settings")}>
            <Settings size={17} />
          </IconButton>
        </div>
        <div
          className="sidebar-resize"
          role="separator"
          aria-label="Resize sidebar"
          aria-orientation="vertical"
          aria-controls="workspace-sidebar"
          aria-valuemin={200}
          aria-valuemax={500}
          aria-valuenow={width}
          tabIndex={0}
          onPointerDown={(event) => {
            if (event.button !== 0) return;
            event.preventDefault();
            dragOffset.current = event.clientX - width;
            event.currentTarget.setPointerCapture(event.pointerId);
            setResizing(true);
          }}
          onLostPointerCapture={() => setResizing(false)}
          onPointerMove={(event) => {
            if (event.currentTarget.hasPointerCapture(event.pointerId))
              setWidth(clampSidebarWidth(event.clientX - dragOffset.current));
          }}
          onPointerUp={(event) => {
            if (event.currentTarget.hasPointerCapture(event.pointerId))
              event.currentTarget.releasePointerCapture(event.pointerId);
          }}
          onKeyDown={(event) => {
            if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key))
              return;
            event.preventDefault();
            setWidth((value) =>
              clampSidebarWidth(
                event.key === "Home"
                  ? 200
                  : event.key === "End"
                    ? 500
                    : value + (event.key === "ArrowLeft" ? -16 : 16),
              ),
            );
          }}
        />
      </aside>
    </>
  );
}
