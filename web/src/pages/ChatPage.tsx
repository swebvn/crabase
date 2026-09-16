import { memo, useEffect, useMemo, useRef, type ReactNode } from "react";
import { ChevronDown, Loader2, Terminal } from "lucide-react";
import { MessageContent } from "../components/MessageContent";
import { AttachmentList } from '../components/AttachmentList';
import type { Approval, Avatars, Chat, Message } from "../types";
import { Avatar } from "../components/Avatar";
import { Crab } from "../components/Crab";
import { time } from "../lib/format";
import { groupConversationMessages } from "../lib/messages";
const MessageItem = memo(function MessageItem({
  message,
  agentName,
  avatars,
  onFileMention,
}: {
  message: Message;
  agentName: string;
  avatars: Avatars;
  onFileMention?: (path: string) => void;
}) {
  const human = message.role === "user" || message.role === "note";
  if (message.role === "tool")
    return (
      <article className="message tool">
        <details className="tool-output">
          <summary>
            <Terminal size={14} aria-hidden="true" />
            <span>{message.author === "Terminal" ? "Command" : message.author}</span>
            <code>{message.body.split("\n")[0]}</code>
            <ChevronDown size={12} aria-hidden="true" />
          </summary>
          <pre>{message.body}</pre>
        </details>
      </article>
    );
  return (
    <article className={`message ${human ? "human" : "agent"} ${message.role}`}>
      <div className="message-author">
        {human ? (
          <Avatar user={message.author} avatars={avatars} />
        ) : (
          <span className="agent-avatar">
            <Crab size={24} />
          </span>
        )}
        <strong>{human ? message.author : agentName}</strong>
        {message.role === "note" && <span className="message-tag">Note</span>}
        {message.role === "guide" && (
          <span className="message-tag">Getting started</span>
        )}
      </div>
      <div className="message-body">
        <AttachmentList files={message.attachments || []} />
        {(message.body || !message.attachments?.length) && <MessageContent onFileMention={onFileMention}>
          {(message.role === "guide"
            ? message.body.replaceAll("Codex", agentName)
            : message.body) || "…"}
        </MessageContent>}
      </div>
    </article>
  );
});
function approvalText(approval: Approval) {
  try {
    const details = JSON.parse(approval.details);
    return (
      details.command || details.reason || JSON.stringify(details, null, 2)
    );
  } catch {
    return approval.details;
  }
}
export function ChatPage({
  chat,
  messages,
  approvals,
  loaded,
  agentName,
  avatars,
  decide,
  onFileMention,
  children,
}: {
  chat?: Chat;
  messages: Message[];
  approvals: Approval[];
  loaded: boolean;
  agentName: string;
  avatars: Avatars;
  decide: (id: number, decision: "accept" | "decline") => void;
  onFileMention?: (path: string) => void;
  children: ReactNode;
}) {
  const messageGroups = useMemo(() => groupConversationMessages(messages), [messages]);
  const scroll = useRef<HTMLDivElement>(null);
  const follow = useRef(true);
  useEffect(() => {
    follow.current = true;
  }, [chat?.id]);
  useEffect(() => {
    if (follow.current && scroll.current)
      scroll.current.scrollTop = scroll.current.scrollHeight;
  }, [messages, approvals, chat?.status]);
  useEffect(() => {
    const element = scroll.current;
    if (!element) return;
    const observer = new ResizeObserver(() => {
      if (follow.current) element.scrollTop = element.scrollHeight;
    });
    observer.observe(element);
    return () => observer.disconnect();
  }, []);
  return (
    <>
      <h1 className="sr-only">{chat?.title || "Chat"}</h1>
      <div
        className="conversation"
        ref={scroll}
        onScroll={() => {
          const el = scroll.current;
          if (el)
            follow.current =
              el.scrollHeight - el.scrollTop - el.clientHeight < 120;
        }}
      >
        <div className="conversation-inner">
          {!loaded && (
            <p className="loading-state" role="status">
              <Loader2 size={16} className="spin" />
              Loading chat…
            </p>
          )}
          {loaded && !messageGroups.length && (
            <p className="muted">Start the conversation below.</p>
          )}
          {messageGroups.map((group) => {
            const first = group[0];
            const last = group[group.length - 1];
            const human = first.role === "user" || first.role === "note";
            return (
              <div className={`message-group ${human ? "human" : "agent"}`} key={first.id}>
                {group.map((message) => (
                  <MessageItem key={message.id} {...{ message, agentName, avatars, onFileMention }} />
                ))}
                <time className="message-time" dateTime={last.created_at}>
                  {time(last.created_at)}
                </time>
              </div>
            );
          })}
          {approvals.map((approval) => (
            <section
              className="approval"
              key={approval.id}
              aria-label="Agent approval"
            >
              <h2>{agentName} needs your approval</h2>
              <pre>{approvalText(approval)}</pre>
              <div className="dialog-actions">
                <button
                  className="button secondary"
                  onClick={() => decide(approval.id, "decline")}
                >
                  Decline
                </button>
                <button
                  className="button primary"
                  onClick={() => decide(approval.id, "accept")}
                >
                  Approve once
                </button>
              </div>
            </section>
          ))}
          {chat && chat.status !== "idle" && (
            <div className="working-indicator" role="status">
              <Loader2 size={15} className="spin" />
              {chat.status === "queued"
                ? "Waiting for the agent…"
                : chat.status === "approval"
                  ? "Waiting for your approval…"
                  : `${agentName} is cooking…`}
            </div>
          )}
        </div>
      </div>
      {children}
    </>
  );
}
