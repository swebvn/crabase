import { useEffect, useRef, useState, type CSSProperties, type ReactNode } from "react";
import {
  ChevronDown,
  ChevronRight,
  Folder,
  FolderOpen,
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
import { Crab } from "./Crab";
import { IconButton, Menu, MenuItem, MenuLabel } from "./ui";
import { useProjectExpansion } from "../hooks/useProjectExpansion";
import { clampSidebarWidth } from "../lib/layout";
import { sortProjects, type ProjectSort } from "../lib/projects";
const projectSortStorageKey = "crabase.project-sort";
const widthStorageKey = "crabase-sidebar-width";
const modifierKey = /Mac|iPhone|iPad/.test(navigator.platform) ? "⌘" : "Ctrl";

function storedProjectSort(): ProjectSort {
  const value = localStorage.getItem(projectSortStorageKey);
  return value === "updated" || value === "name" || value === "created" ? value : "created";
}

type Props = {
  projects: Project[];
  pins: string[];
  pinProject: (project: Project) => void;
  admin: boolean;
  chats: Chat[];
  selected: string;
  activeProjectId?: string;
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
  useEffect(() => {
    const toggle = () => setShowArchived((value) => !value);
    window.addEventListener("crabase:toggle-archived", toggle);
    return () => window.removeEventListener("crabase:toggle-archived", toggle);
  }, []);
  const [width, setWidth] = useState(() => clampSidebarWidth(Number(localStorage.getItem(widthStorageKey)) || 232));
  const [resizing, setResizing] = useState(false);
  useEffect(() => localStorage.setItem(widthStorageKey, String(width)), [width]);
  const [showAll, setShowAll] = useState<string[]>([]);
  const [projectSort, setProjectSort] = useState<ProjectSort>(storedProjectSort);
  const dragOffset = useRef(0);
  const { collapsed, projectsOpen, toggleProjects, toggleProject } = useProjectExpansion();
  useEffect(() => {
    localStorage.setItem(projectSortStorageKey, projectSort);
  }, [projectSort]);
  const activeParent = projects.find((project) => project.id === activeProjectId)?.parent_id;
  useEffect(() => {
    if (!activeProjectId) return;
    if (!projectsOpen && !pins.includes(activeParent || activeProjectId)) toggleProjects();
    for (const id of [activeParent, activeProjectId]) if (id && collapsed.includes(id)) toggleProject(id);
  }, [activeProjectId, activeParent]);
  const visibleProjects = sortProjects(projects.filter((project) => showArchived || !project.archived), chats, projectSort).filter((project) => !project.parent_id);
  const pinnedProjects = visibleProjects.filter((project) => pins.includes(project.id));
  const standaloneChats = chats.filter((chat) => !chat.project_id && !chat.archived);
  const renderProjects = (items: Project[]): ReactNode => items.map((project) => {
    const expanded = !collapsed.includes(project.id);
    const projectChats = chats.filter(
      (chat) => chat.project_id === project.id && !chat.archived,
    );
    const shownChats = showAll.includes(project.id)
      ? projectChats
      : projectChats.filter((chat, index) => index < 5 || chat.id === selected);
    const busy = !expanded && chats.some((chat) => !chat.archived && chat.status !== "idle" && (chat.project_id === project.id
      || projects.some((child) => child.id === chat.project_id && child.parent_id === project.id)));
    return (
      <section
        className={`project-group ${project.parent_id ? 'worktree-group' : ''}`}
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
            {project.parent_id ? <GitBranch size={16} /> : expanded ? (
              <FolderOpen size={16} />
            ) : (
              <Folder size={16} />
            )}
            <span className="truncate" title={project.parent_id ? `${project.name}\n${project.path}` : project.name}>{project.name}{project.archived ? " · Archived" : ""}</span>
            {busy && <Loader2 size={13} className="spin" aria-label="Agent working" />}
          </button>
          <div className="project-actions">
          {(!project.parent_id || admin) && <Menu viewport label={`Project options for ${project.name}`} icon={<MoreHorizontal size={14} />}>
            {!project.parent_id && <MenuItem onClick={() => pinProject(project)}>
              {pins.includes(project.id) ? "Unpin" : "Pin"}
            </MenuItem>}
            {admin && !project.parent_id && !project.archived && <MenuItem onClick={() => createWorktree(project)}>Create worktree</MenuItem>}
            {admin && <><MenuItem onClick={() => manageProject(project, "projectArchive")}>
              {project.archived ? "Restore" : "Archive"}
            </MenuItem>
            <MenuItem onClick={() => manageProject(project, "projectDelete")}>Delete</MenuItem></>}
          </Menu>}
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
            {!project.parent_id && renderProjects(sortProjects(projects.filter((child) => child.parent_id === project.id && (showArchived || !child.archived)), chats, 'updated'))}
          </div>
        )}
      </section>
    );
  });
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
        <div className="brand-row">
          <button className="brand" onClick={() => newChat()}>
            <Crab size={26} />
            <span>Crabase</span>
          </button>
          <IconButton label="Close sidebar" onClick={close}>
            <PanelLeft size={17} />
          </IconButton>
        </div>
        <nav aria-label="Workspace" className="primary-nav">
          <button
            className={`nav-item ${!selected ? "selected" : ""}`}
            onClick={() => newChat()}
          >
            <SquarePen size={17} />
            <span>New chat</span>
            <kbd>{modifierKey} ⇧ O</kbd>
          </button>
        </nav>
        <div className="sidebar-scroll">
          {pinnedProjects.length > 0 && <section aria-label="Pins">
            <div className="section-label">Pins</div>
            {renderProjects(pinnedProjects)}
          </section>}
          <div className="section-label">
            <button
              aria-expanded={projectsOpen}
              aria-controls="project-list"
              onClick={toggleProjects}
            >
              Projects
              {projectsOpen ? <ChevronDown size={12} /> : <ChevronRight size={12} />}
            </button>
            <div className="section-actions">
              <Menu label="Sort projects" icon={<MoreHorizontal size={16} />}>
                <MenuLabel>Sort by</MenuLabel>
                {(
                  [
                    ["updated", "Latest update"],
                    ["name", "Name"],
                    ["created", "Default (created)"],
                  ] as const
                ).map(([value, label]) => (
                  <MenuItem
                    key={value}
                    selected={projectSort === value}
                    onClick={() => setProjectSort(value)}
                  >
                    {label}
                  </MenuItem>
                ))}
                <MenuItem selected={showArchived} onClick={() => setShowArchived((value) => !value)}>
                  Show archived projects
                </MenuItem>
              </Menu>
              {admin && <IconButton
                label="Add project"
                onClick={() => showDialog("project")}
              >
                <Plus size={15} />
              </IconButton>}
            </div>
          </div>
          {projectsOpen && (
            <div id="project-list">
              {renderProjects(visibleProjects.filter((project) => !pins.includes(project.id)))}
            </div>
          )}
          {standaloneChats.length > 0 && <div className="section-label">Chats</div>}
          {standaloneChats.map((chat) => (
            <ChatLink
              key={chat.id}
              {...{ chat, selected, avatars, open, archive, rename }}
            />
          ))}
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
