import type { Chat, Project } from "../types";
import { matchScore } from "./file-search.ts";

export type Command = {
  id: string;
  label: string;
  keywords?: string[];
  shortcut?: string;
  run: () => void;
};

export function filterCommands(commands: Command[], query: string) {
  const value = query.trim().toLowerCase();
  if (!value) return commands;
  return commands.filter((command) => [command.label, ...(command.keywords || [])]
    .join(" ").toLowerCase().includes(value));
}

export function searchChats(chats: Chat[], projects: Project[], query: string) {
  const value = query.trim().toLowerCase();
  const projectNames = new Map(projects.map(project => [project.id, project.name]));
  return chats.flatMap((chat, index) => {
    if (!value && chat.archived) return [];
    const project = chat.project_id ? projectNames.get(chat.project_id) || "" : "";
    const scores = [fuzzyScore(chat.title, value), project ? fuzzyScore(project, value) : null]
      .filter((score): score is number => score !== null);
    return scores.length ? [{ chat, index, score: Math.min(...scores) }] : [];
  }).sort((a, b) => a.score - b.score || a.index - b.index).map(({ chat }) => chat);
}

export function fuzzyScore(text: string, query: string) {
  if (!query) return 0;
  const score = matchScore(text.toLowerCase(), query.toLowerCase());
  return Number.isFinite(score) ? score : null;
}
