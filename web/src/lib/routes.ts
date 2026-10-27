export type Route =
  | { page: "new" }
  | { page: "settings" }
  | { page: "chat"; id: string }
  | { page: "project"; id: string; newChat: boolean }
  | { page: "missing" };
export function parseRoute(path: string): Route {
  if (path === "/") return { page: "new" };
  if (path === "/settings" || path === "/settings/") return { page: "settings" };
  const project = /^\/project\/([a-zA-Z0-9_-]{1,64})(\/new)?\/?$/.exec(path);
  if (project) return { page: "project", id: project[1], newChat: !!project[2] };
  const match = /^\/chat\/([a-f0-9]{16})\/?$/.exec(path);
  return match ? { page: "chat", id: match[1] } : { page: "missing" };
}
export function chatPath(id: string) {
  return `/chat/${encodeURIComponent(id)}`;
}
export function projectPath(id: string, newChat = false) {
  return `/project/${encodeURIComponent(id)}${newChat ? "/new" : ""}`;
}
