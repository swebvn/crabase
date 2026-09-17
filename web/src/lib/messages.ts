import type { Message } from "../types";

export function nextStreamText(target: string, visible: string, elapsed: number) {
  if (!target.startsWith(visible)) return target;
  const pending = Array.from(target.slice(visible.length));
  if (!pending.length) return visible;
  const rate = pending.length > 400 ? 1800 : pending.length > 120 ? 900 : pending.length > 40 ? 360 : 180;
  const count = Math.max(1, Math.min(pending.length, Math.round(rate * Math.max(24, elapsed) / 1000)));
  return visible + pending.slice(0, count).join("");
}

export function groupConversationMessages(messages: Message[]) {
  const groups: Message[][] = [];
  const isAgent = (message: Message) => ["assistant", "tool"].includes(message.role);
  for (const message of messages) {
    if (message.role === "agent_activity") continue;
    const previous = groups[groups.length - 1];
    if (previous && isAgent(previous[0]) && isAgent(message)) previous.push(message);
    else groups.push([message]);
  }
  return groups;
}

export function applyMessagePatch(
  previous: Message[],
  updates: Message[] = [],
  appends: { id: number; delta: string }[] = [],
) {
  const items = new Map(previous.map((message) => [message.id, message]));
  for (const message of updates) items.set(message.id, message);
  for (const append of appends) {
    const message = items.get(append.id);
    if (message)
      items.set(append.id, { ...message, body: message.body + append.delta });
  }
  return [...items.values()].sort((a, b) => a.id - b.id);
}
