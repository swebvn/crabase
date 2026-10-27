export type Route =
  | { page: "new"; projectId?: string }
  | { page: "settings" }
  | { page: "chat"; id: string; projectId?: string }
  | { page: "project"; id: string; newChat: boolean }
  | { page: "missing" };
export function parseRoute(path: string): Route {
  if (path === "/") return { page: "new" };
  if (path === "/settings" || path === "/settings/") return { page: "settings" };
  const focusedChat = /^\/project\/([a-zA-Z0-9_-]{1,64})\/chat\/([a-f0-9]{16})\/?$/.exec(path);
  if (focusedChat) return { page: "chat", projectId: focusedChat[1], id: focusedChat[2] };
  const newChat = /^\/new\/([a-zA-Z0-9_-]{1,64})\/?$/.exec(path);
  if (newChat) return { page: "new", projectId: newChat[1] };
  const project = /^\/project\/([a-zA-Z0-9_-]{1,64})(\/new)?\/?$/.exec(path);
  if (project) return { page: "project", id: project[1], newChat: !!project[2] };
  const match = /^\/chat\/([a-f0-9]{16})\/?$/.exec(path);
  return match ? { page: "chat", id: match[1] } : { page: "missing" };
}
export function chatPath(id: string, projectId?: string) {
  return `${projectId ? projectPath(projectId) : ""}/chat/${encodeURIComponent(id)}`;
}
export function newChatPath(projectId = "", focused = false) {
  return projectId ? focused ? projectPath(projectId, true) : `/new/${encodeURIComponent(projectId)}` : "/";
}
export function projectPath(id: string, newChat = false) {
  return `/project/${encodeURIComponent(id)}${newChat ? "/new" : ""}`;
}
