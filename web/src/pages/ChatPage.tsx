import { Button } from "../components/ui";
import { memo, useEffect, useMemo, useRef, type ReactNode } from "react";
import { ChevronDown, FilePen, ListChecks, Loader2, Terminal } from "lucide-react";
import { MessageContent } from "../components/MessageContent";
import { AttachmentList } from '../components/AttachmentList';
import type { Approval, Avatars, Chat, Message } from "../types";
import { Avatar } from "../components/Avatar";
import { Crab } from "../components/Crab";
import { time } from "../lib/format";
import { groupConversationMessages, summarizeTurn } from "../lib/messages";
import { useSmoothText } from "../hooks/useSmoothText";
/** One agent header per turn; intermediate replies and tools in the turn share it. */
function AgentAuthor({ agentName }: { agentName: string }) {
  return (
    <div className="message-author">
      <span className="agent-avatar"><Crab size={24} /></span>
      <strong>{agentName}</strong>
    </div>
  );
}
const MessageItem = memo(function MessageItem({
  message,
  agentName,
  avatars,
  onFileMention,
  streaming,
  showAuthor = true,
}: {
  message: Message;
  agentName: string;
  avatars: Avatars;
  onFileMention?: (path: string) => void;
  streaming: boolean;
  showAuthor?: boolean;
}) {
  const human = message.role === "user" || message.role === "note";
  const body =
    message.role === "guide"
      ? message.body.replaceAll("Codex", agentName)
      : message.body;
  const visibleBody = useSmoothText(body, streaming);
  if (message.role === "tool")
    return (
      <article className="message tool">
        <details className="tool-output">
          <summary>
            {message.author === "File changes" ? <FilePen size={14} aria-hidden="true" /> : <Terminal size={14} aria-hidden="true" />}
            <span>{message.author === "Terminal" ? "Command" : message.author}</span>
            <code>{message.body.split("\n")[0]}</code>
            <ChevronDown size={12} aria-hidden="true" />
          </summary>
          <pre>{message.body}</pre>
        </details>
      </article>
    );
  return (
    <article
      className={`message ${human ? "human" : "agent"} ${message.role}`}
      aria-busy={streaming}
    >
      {showAuthor && (human ? <div className="message-author">
        <Avatar user={message.author} avatars={avatars} />
        <strong>{message.author}</strong>
        {message.role === "note" && <span className="message-tag">Note</span>}
      </div> : message.role === "guide" ? <div className="message-author">
        <span className="agent-avatar"><Crab size={24} /></span>
        <strong>{agentName}</strong>
        <span className="message-tag">Getting started</span>
      </div> : <AgentAuthor agentName={agentName} />)}
      <div className="message-body">
        <AttachmentList files={message.attachments || []} />
        {(message.body || !message.attachments?.length) && <MessageContent onFileMention={onFileMention}>
          {visibleBody || "…"}
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
  hasMoreMessages,
  loadingEarlier,
  loadEarlier,
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
  hasMoreMessages: boolean;
  loadingEarlier: boolean;
  loadEarlier: () => Promise<void>;
}) {
  const messageGroups = useMemo(() => groupConversationMessages(messages), [messages]);
  const scroll = useRef<HTMLDivElement>(null);
  const historySentinel = useRef<HTMLDivElement>(null);
  const follow = useRef(true);
  const streamingId = useMemo(() => {
    if (chat?.status !== "running") return null;
    for (let index = messages.length - 1; index >= 0; index -= 1) {
      if (messages[index].role === "assistant") return messages[index].id;
    }
    return null;
  }, [chat?.status, messages]);
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
  const loadEarlierPreservingPosition = () => {
    const element = scroll.current;
    const height = element?.scrollHeight || 0;
    const top = element?.scrollTop || 0;
    void loadEarlier().then(() => requestAnimationFrame(() => {
      if (element) element.scrollTop = top + element.scrollHeight - height;
    }));
  };
  useEffect(() => {
    const sentinel = historySentinel.current;
    const element = scroll.current;
    if (!sentinel || !element || !hasMoreMessages) return;
    const observer = new IntersectionObserver(([entry]) => {
      if (entry.isIntersecting && !loadingEarlier) loadEarlierPreservingPosition();
    }, { root: element, rootMargin: "160px 0px 0px" });
    observer.observe(sentinel);
    return () => observer.disconnect();
  }, [hasMoreMessages, loadingEarlier, loadEarlier]);
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
          {hasMoreMessages && <>
            <div ref={historySentinel} aria-hidden="true" />
          </>}
          {!loaded && (
            <p className="loading-state" role="status">
              <Loader2 size={16} className="spin" />
              Loading chat…
            </p>
          )}
          {loaded && !messageGroups.length && (
            <p className="muted">Start the conversation below.</p>
          )}
          {messageGroups.map((group, groupIndex) => {
            const first = group[0];
            const last = group[group.length - 1];
            const human = first.role === "user" || first.role === "note";
            const agent = ["assistant", "tool"].includes(first.role);
            // The turn still in progress stays expanded; finished turns collapse their work to one line.
            const active = groupIndex === messageGroups.length - 1 && !!chat && chat.status !== "idle";
            const summary = agent && !active ? summarizeTurn(group) : null;
            const item = (message: Message) => (
              <MessageItem
                key={message.id}
                {...{ message, agentName, avatars, onFileMention }}
                streaming={message.id === streamingId}
                showAuthor={!agent}
              />
            );
            return (
              <div className={`message-group ${human ? "human" : "agent"}`} key={first.id}>
                {agent && <AgentAuthor agentName={agentName} />}
                {summary ? <>
                  <details className="tool-output turn-summary">
                    <summary>
                      <ListChecks size={14} aria-hidden="true" />
                      <span>{summary.label}</span>
                      <ChevronDown size={12} aria-hidden="true" />
                    </summary>
                    <div className="turn-work">{summary.work.map(item)}</div>
                  </details>
                  {summary.final && item(summary.final)}
                </> : group.map(item)}
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
                <Button
                  variant="secondary"
                  onClick={() => decide(approval.id, "decline")}
                >
                  Decline
                </Button>
                <Button
                  variant="primary"
                  onClick={() => decide(approval.id, "accept")}
                >
                  Approve once
                </Button>
              </div>
            </section>
          ))}
          {chat && chat.status !== "idle" && (
            <div className="working-indicator" role="status">
              <Loader2 size={14} className="spin" />
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
