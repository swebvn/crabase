import { useCallback, useEffect, useRef, useState } from "react";
import type { Snapshot, Message, Approval, Artifact, TerminalSession } from "../types";
import { applyMessagePatch } from "../lib/messages";
import { appendTerminalOutput } from "../lib/terminal";
const empty: Snapshot = {
  pins: [],
  users: [],
  agentName: "Crab",
  models: [],
  projects: [],
  chats: [],
  events: [],
  runtime: "offline",
};
export function useWorkspace(selected: string) {
  const [data, setData] = useState<Snapshot>(empty);
  const [loaded, setLoaded] = useState(false);
  const [live, setLive] = useState(false);
  const [messages, setMessages] = useState<Message[]>([]);
  const [artifacts, setArtifacts] = useState<Artifact[]>([]);
  const [approvals, setApprovals] = useState<Approval[]>([]);
  const [terminals, setTerminals] = useState<TerminalSession[]>([]);
  const [terminalChatId, setTerminalChatId] = useState("");
  const [error, setError] = useState("");
  const [hasMoreMessages, setHasMoreMessages] = useState(false);
  const [loadingEarlier, setLoadingEarlier] = useState(false);
  const threadCache = useRef(new Map<string, { messages: Message[]; approvals: Approval[]; artifacts: Artifact[]; terminals: TerminalSession[]; hasMore: boolean }>());
  const dismissedTerminals = useRef(new Set<string>());
  const terminalOutputQueue = useRef(new Map<string, string>());
  const terminalFlush = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
  const selectedRef = useRef(selected);
  selectedRef.current = selected;
  const socketRef = useRef<WebSocket | null>(null);
  const sequence = useRef(0);
  const pending = useRef(
    new Map<
      number,
      {
        resolve: (data: unknown) => void;
        reject: (error: Error) => void;
        timer: ReturnType<typeof setTimeout>;
      }
    >(),
  );
  const request = useCallback(
    <T = unknown>(action: string, data: unknown = {}): Promise<T> => {
      const socket = socketRef.current;
      if (!socket || socket.readyState !== WebSocket.OPEN)
        return Promise.reject(
          new Error(
            "Workspace disconnected. Wait for reconnection before sending.",
          ),
        );
      const id = ++sequence.current;
      return new Promise((resolve, reject) => {
        const timer = setTimeout(() => {
          pending.current.delete(id);
          reject(
            new Error(
              "Confirmation timed out. Check the chat before retrying; your action may have been saved.",
            ),
          );
        }, 15000);
        pending.current.set(id, {
          resolve: (result) => resolve(result as T),
          reject,
          timer,
        });
        socket.send(JSON.stringify({ id, action, data }));
      });
    },
    [],
  );
  useEffect(() => {
    let retry: ReturnType<typeof setTimeout>,
      disposed = false;
    const rejectPending = () => {
      for (const entry of pending.current.values()) {
        clearTimeout(entry.timer);
        entry.reject(
          new Error(
            "Connection lost before confirmation. Check the chat after reconnecting before retrying.",
          ),
        );
      }
      pending.current.clear();
    };
    function connect() {
      const endpoint =
        location.port === "5173"
          ? `${location.host}/live`
          : `${location.hostname}:8788`;
      const socket = new WebSocket(
        `${location.protocol === "https:" ? "wss" : "ws"}://${endpoint}`,
      );
      socketRef.current = socket;
      socket.onopen = () => {
        if (!disposed) setLive(true);
      };
      socket.onmessage = (event) => {
        if (disposed) return;
        const packet = JSON.parse(event.data);
        if (packet.type === "unauthorized") {
          disposed = true;
          rejectPending();
          window.dispatchEvent(new Event('crabase:unauthorized'));
          socket.close();
          return;
        }
        if (typeof packet.id === "number") {
          const entry = pending.current.get(packet.id);
          if (!entry) return;
          clearTimeout(entry.timer);
          pending.current.delete(packet.id);
          if (packet.error) entry.reject(new Error(packet.error));
          else entry.resolve(packet.result);
          return;
        }
        if (packet.type === "terminal" && packet.chat_id === selectedRef.current) {
          const terminalId = packet.terminal?.id || packet.terminal_id;
          if (terminalId && dismissedTerminals.current.has(terminalId)) {
            if (packet.event === "closed") dismissedTerminals.current.delete(terminalId);
            return;
          }
          if (packet.event === "opened")
            setTerminals((previous) => [
              ...previous.filter((item) => item.id !== packet.terminal.id),
              packet.terminal,
            ]);
          if (packet.event === "output") {
            const id = packet.terminal_id as string;
            terminalOutputQueue.current.set(id, (terminalOutputQueue.current.get(id) || "") + packet.data);
            if (!terminalFlush.current) {
              terminalFlush.current = setTimeout(() => {
                terminalFlush.current = undefined;
                const queued = terminalOutputQueue.current;
                terminalOutputQueue.current = new Map();
                setTerminals((previous) => previous.map((item) => {
                  const data = queued.get(item.id);
                  return data ? appendTerminalOutput(item, data) : item;
                }));
              }, 16);
            }
          }
          if (packet.event === "exit" || packet.event === "error")
            setTerminals((previous) => previous.map((item) =>
              item.id === packet.terminal_id ? { ...item, running: false } : item,
            ));
          if (packet.event === "closed")
            setTerminals((previous) => previous.filter((item) => item.id !== packet.terminal_id));
          if (packet.event === "closed") dismissedTerminals.current.delete(packet.terminal_id);
          if (packet.event === "error") setError(packet.message || "Terminal stopped.");
          return;
        }
        if (packet.type !== "patch") return;
        if (packet.access_revoked) {
          setMessages([]); setArtifacts([]); setApprovals([]); setTerminals([]);
        }
        if (packet.state)
          setData((previous) => ({ ...previous, ...packet.state }));
        if (packet.chat_id === selectedRef.current) {
          if (packet.messages || packet.append)
            setMessages((previous) =>
              applyMessagePatch(previous, packet.messages, packet.append),
            );
          if (packet.artifacts) setArtifacts(packet.artifacts);
          if (packet.approvals) setApprovals(packet.approvals);
        }
      };
      socket.onclose = async () => {
        if (disposed || socketRef.current !== socket) return;
        setLive(false);
        rejectPending();
        try {
          const response = await fetch('/auth/session', { credentials: 'same-origin' });
          if (disposed) return;
          if (response.status === 401) {
            window.dispatchEvent(new Event('crabase:unauthorized'));
            return;
          }
        } catch { /* Retry the socket when the server is temporarily unavailable. */ }
        if (disposed) return;
        retry = setTimeout(connect, 1000);
      };
    }
    connect();
    return () => {
      disposed = true;
      clearTimeout(retry);
      if (terminalFlush.current) clearTimeout(terminalFlush.current);
      rejectPending();
      socketRef.current?.close();
    };
  }, []);
  const dismissTerminal = useCallback((id: string) => {
    dismissedTerminals.current.add(id);
    setTerminals((previous) => previous.filter((item) => item.id !== id));
    for (const [chatId, thread] of threadCache.current) {
      threadCache.current.set(chatId, {
        ...thread,
        terminals: thread.terminals.filter((item) => item.id !== id),
      });
    }
  }, []);
  useEffect(() => {
    const cached = selected ? threadCache.current.get(selected) : undefined;
    setMessages(cached?.messages || []);
    setApprovals(cached?.approvals || []);
    setArtifacts(cached?.artifacts || []);
    setTerminals(cached?.terminals || []);
    setHasMoreMessages(cached?.hasMore || false);
    setLoaded(!!cached);
    if (!live) return;
    const id = selected;
    const latestCachedId = cached?.messages.at(-1)?.id;
    let stale = false;
    request<{
      state: Snapshot;
      thread: {
        artifacts: Artifact[];
        messages: Message[];
        approvals: Approval[];
        pagination?: { has_more: boolean; oldest_id: number | null };
      } | null;
      terminals: TerminalSession[];
    }>("sync", {
      chat_id: id || null,
      ...(id && latestCachedId ? { after: latestCachedId } : {}),
      limit: 150,
    })
      .then((result) => {
        if (stale) return;
        const nextMessages =
          id && cached
            ? applyMessagePatch(cached.messages, result.thread?.messages || [])
            : result.thread?.messages || [];
        setData(result.state);
        setLoaded(true);
        setArtifacts(result.thread?.artifacts || []);
        const nextHasMore = latestCachedId
          ? (cached?.hasMore || false)
          : (result.thread?.pagination?.has_more || false);
        setHasMoreMessages(nextHasMore);
        setMessages(nextMessages);
        setApprovals(result.thread?.approvals || []);
        const serverTerminalIds = new Set((result.terminals || []).map((terminal) => terminal.id));
        for (const terminalId of dismissedTerminals.current) {
          if (!serverTerminalIds.has(terminalId)) dismissedTerminals.current.delete(terminalId);
        }
        const nextTerminals = (result.terminals || []).filter((terminal) => !dismissedTerminals.current.has(terminal.id));
        setTerminals(nextTerminals);
        setTerminalChatId(id);
        if (id) threadCache.current.set(id, { messages: nextMessages, approvals: result.thread?.approvals || [], artifacts: result.thread?.artifacts || [], terminals: nextTerminals, hasMore: nextHasMore });
      })
      .catch(async (error) => {
        if (stale) return;
        setError(error.message);
        // A private/deleted chat URL must not leave the workspace stuck loading.
        if (id) {
          try {
            const result = await request<{ state: Snapshot }>('sync');
            if (!stale) { setData(result.state); setLoaded(true); }
          } catch { /* Preserve the original error; reconnect will retry. */ }
        }
      });
    return () => {
      stale = true;
    };
  }, [selected, live, request]);

  const loadEarlier = useCallback(async () => {
    if (!selected || loadingEarlier || !hasMoreMessages) return;
    const oldest = messages[0]?.id;
    if (!oldest) return;
    setLoadingEarlier(true);
    try {
      const result = await request<{ thread: { messages: Message[]; pagination?: { has_more: boolean } } }>("sync", { chat_id: selected, before: oldest, limit: 150 });
      setMessages((previous) => applyMessagePatch([...result.thread.messages, ...previous]));
      setHasMoreMessages(result.thread.pagination?.has_more || false);
      const cached = threadCache.current.get(selected);
      if (cached) threadCache.current.set(selected, { ...cached, messages: applyMessagePatch([...result.thread.messages, ...cached.messages]), hasMore: result.thread.pagination?.has_more || false });
    } finally {
      setLoadingEarlier(false);
    }
  }, [selected, loadingEarlier, hasMoreMessages, messages, request]);

  return {
    data,
    loaded,
    live,
    messages,
    approvals,
    artifacts,
    terminals,
    terminalChatId,
    hasMoreMessages,
    loadingEarlier,
    loadEarlier,
    error,
    setError,
    request,
    dismissTerminal,
  };
}
