import { useEffect, useRef } from "react";
import type { Chat, Project } from "../types";
import { chatPath, projectPath, type Route } from "../lib/routes";
import { resumeProjectChat, rootProject } from "../lib/projects";

export function useProjectNavigation(route: Route, projects: Project[], chats: Chat[], loaded: boolean, userId: string,
  navigate: (path: string, replace?: boolean) => void) {
  const storageKey = `crabase.last-chats:${userId}`;
  const lastChats = useRef<Record<string, string>>({});
  const settingsBack = useRef("/");
  useEffect(() => {
    try {
      const saved: unknown = JSON.parse(localStorage.getItem(storageKey) || "{}");
      lastChats.current = saved && typeof saved === "object" && !Array.isArray(saved)
        ? Object.fromEntries(Object.entries(saved).filter(([, value]) => typeof value === "string")) : {};
    } catch { lastChats.current = {}; }
  }, [storageKey]);
  const chat = route.page === "chat" ? chats.find(chat => chat.id === route.id) : undefined;
  const projectId = route.page === "project" ? route.id : chat?.project_id || "";
  const project = projects.find(project => project.id === projectId);
  const scope = rootProject(projectId, projects);
  useEffect(() => {
    if (route.page === "settings" || route.page === "missing") return;
    settingsBack.current = route.page === "chat" ? chatPath(route.id) : route.page === "project" ? projectPath(route.id, route.newChat) : "/";
  }, [route]);
  useEffect(() => {
    if (!loaded || !chat || chat.archived || (chat.project_id && !scope)) return;
    const key = scope ? `project:${scope.id}` : "standalone";
    if (lastChats.current[key] === chat.id) return;
    lastChats.current = { ...lastChats.current, [key]: chat.id };
    try { localStorage.setItem(storageKey, JSON.stringify(lastChats.current)); } catch { /* Selection still works without storage. */ }
  }, [loaded, chat?.id, chat?.archived, scope?.id, storageKey]);
  useEffect(() => {
    if (!loaded || route.page !== "project" || route.newChat || !project) return;
    const target = resumeProjectChat(project.id, projects, chats, lastChats.current[`project:${scope?.id || project.id}`]);
    navigate(target ? chatPath(target.id) : projectPath(project.id, true), true);
  }, [route, loaded, project, projects, chats, scope?.id, navigate]);
  function switchProject(id: string) {
    const target = id ? undefined : resumeProjectChat("", projects, chats, lastChats.current.standalone);
    navigate(id ? projectPath(id) : target ? chatPath(target.id) : "/");
  }
  return { chat, projectId, project, scope, switchProject, backFromSettings: () => navigate(settingsBack.current) };
}
