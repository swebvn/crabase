import { Copy, FileCode2, MoreHorizontal, PanelLeft, PanelRight, SquareTerminal } from "lucide-react";
import type { Avatars, Chat, Project } from "../types";
import { IconButton, Menu } from "./ui";
import { AvatarStack } from "./Avatar";
export function Header({
  chat,
  project,
  showSidebar,
  sidebarHidden,
  toggleDetails,
  detailsOpen,
  toggleCode,
  codeOpen,
  toggleTerminal,
  terminalOpen,
  copy,
  avatars,
}: {
  chat?: Chat;
  project?: Project;
  showSidebar: () => void;
  sidebarHidden: boolean;
  toggleDetails: () => void;
  detailsOpen: boolean;
  toggleCode: () => void;
  codeOpen: boolean;
  toggleTerminal: () => void;
  terminalOpen: boolean;
  copy: () => void;
  avatars: Avatars;
}) {
  return (
    <header className="topbar">
      <div className="breadcrumb">
        <IconButton
          className={`sidebar-opener ${sidebarHidden ? "show" : ""}`}
          label="Open sidebar"
          onClick={showSidebar}
        >
          <PanelLeft size={18} />
        </IconButton>
        {project && (
          <>
            <span className="breadcrumb-project truncate">{project.name}</span>
            <span className="slash">/</span>
          </>
        )}
        <span className="truncate" title={chat?.title}>
          {chat?.title || "New chat"}
        </span>
        {chat && <AvatarStack users={chat.participants} avatars={avatars} />}
      </div>
      <div className="topbar-actions">
        <div className="mobile-header-menu">
          <Menu label="Chat actions" icon={<MoreHorizontal size={18} />}>
            {chat && <button onClick={copy}><Copy size={16} />Copy chat link</button>}
            {chat && <button onClick={toggleTerminal}><SquareTerminal size={17} />{terminalOpen ? "Hide terminal" : "Show terminal"}</button>}
            {project && <button onClick={toggleCode}><FileCode2 size={18} />{codeOpen ? "Hide code" : "Show code"}</button>}
            <button onClick={toggleDetails}><PanelRight size={18} />{detailsOpen ? "Hide artifacts" : "Show artifacts"}</button>
          </Menu>
        </div>
        {chat && (
          <>
            <IconButton label="Copy chat link" onClick={copy}>
              <Copy size={16} />
            </IconButton>
            <IconButton
              className={terminalOpen ? "active" : ""}
              label={`${terminalOpen ? "Hide" : "Show"} terminal (⌘J)`}
              aria-pressed={terminalOpen}
              onClick={toggleTerminal}
            >
              <SquareTerminal size={17} />
            </IconButton>
          </>
        )}
        {project && <IconButton
          className={codeOpen ? "active" : ""}
          label={`${codeOpen ? "Hide" : "Show"} code workspace`}
          aria-pressed={codeOpen}
          onClick={toggleCode}
        >
          <FileCode2 size={18} />
        </IconButton>}
        <IconButton
          className={detailsOpen ? "active" : ""}
          label={`${detailsOpen ? "Hide" : "Open"} artifacts`}
          aria-pressed={detailsOpen}
          onClick={toggleDetails}
        >
          <PanelRight size={18} />
        </IconButton>
      </div>
    </header>
  );
}
