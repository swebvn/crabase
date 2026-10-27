import type { Chat, Project } from "../types";

export type ProjectSort = "updated" | "name" | "created";

export function rootProject(projectId: string | null | undefined, projects: Project[]) {
  const project = projects.find(item => item.id === projectId);
  return project?.parent_id ? projects.find(item => item.id === project.parent_id) : project;
}

export function resumeProjectChat(projectId: string, projects: Project[], chats: Chat[], remembered?: string) {
  if (projectId && !projects.some(project => project.id === projectId)) return undefined;
  const folders = new Set(projects.filter(project => project.id === projectId || (project.parent_id === projectId && !project.archived)).map(project => project.id));
  const available = chats.filter(chat => !chat.archived && (projectId ? !!chat.project_id && folders.has(chat.project_id) : !chat.project_id));
  return available.find(chat => chat.id === remembered)
    || available.reduce<Chat | undefined>((latest, chat) => !latest || chat.updated_at > latest.updated_at ? chat : latest, undefined);
}

export function sortProjects(
  projects: Project[],
  chats: Chat[],
  sort: ProjectSort,
) {
  const updated = new Map<string, string>();
  const parents = new Map(projects.map((project) => [project.id, project.parent_id]));
  for (const chat of chats)
    if (
      chat.project_id &&
      chat.updated_at > (updated.get(chat.project_id) || "")
    )
      updated.set(chat.project_id, chat.updated_at);
  for (const [id, date] of [...updated]) {
    const parent = parents.get(id);
    if (parent && date > (updated.get(parent) || '')) updated.set(parent, date);
  }

  return [...projects].sort((a, b) => {
    if (sort === "name") return a.name.localeCompare(b.name);
    if (sort === "updated")
      return (updated.get(b.id) || "").localeCompare(updated.get(a.id) || "") || b.created_order - a.created_order;
    return b.created_order - a.created_order;
  });
}
