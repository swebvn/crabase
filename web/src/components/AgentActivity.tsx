import { useState } from "react";
import { Check, ChevronDown, Circle, CircleAlert, Loader2 } from "lucide-react";
import { MessageContent } from "./MessageContent";

type Agent = {
  id: string;
  name?: string;
  task: string;
  message: string;
  status: string;
  logs?: Record<string, string>;
};
const labels: Record<string, string> = {
  waiting: "Waiting for approval",
  pendingInit: "Starting",
  running: "Working",
  completed: "Done",
  interrupted: "Interrupted",
  errored: "Failed",
  shutdown: "Stopped",
  notFound: "Unavailable",
  unknown: "No final status reported",
};
export function AgentActivity({ body }: { body: string }) {
  const [expanded, setExpanded] = useState<boolean | null>(null);
  let activity: { active: boolean; agents: Record<string, Agent> };
  try {
    activity = JSON.parse(body);
  } catch {
    return null;
  }
  if (!activity?.agents) return null;
  const agents = Object.values(activity.agents);
  const working = agents.filter((agent) =>
    ["pendingInit", "running"].includes(agent.status),
  ).length;
  const completed = agents.filter(
    (agent) => agent.status === "completed",
  ).length;
  const needsAttention = agents.some((agent) =>
    ["errored", "interrupted", "notFound", "unknown", "waiting"].includes(
      agent.status,
    ),
  );
  const open = expanded ?? (activity.active || needsAttention);
  return (
    <section className="agent-activity" aria-label="Agent activity">
      <button
        className="agent-activity-heading"
        aria-expanded={open}
        onClick={() => setExpanded(!open)}
      >
        <strong>Agent activity</strong>
        <span>
          {working ? `${working} working · ` : ""}
          {completed} of {agents.length} done
        </span>
        <ChevronDown size={14} className={open ? "" : "collapsed"} />
      </button>
      <div className="agent-activity-list" hidden={!open}>
        {agents.map((agent, index) => {
          const running = ["pendingInit", "running"].includes(agent.status);
          const failed = ["errored", "notFound"].includes(agent.status);
          const title =
            agent.name?.replaceAll("_", " ") ||
            agent.task.split("\n")[0].slice(0, 100) ||
            `Task ${index + 1}`;
          return (
            <details className="agent-task" key={agent.id}>
              <summary>
                {running ? (
                  <Loader2 size={14} className="spin" />
                ) : agent.status === "completed" ? (
                  <Check size={14} />
                ) : failed ? (
                  <CircleAlert size={14} className="agent-task-error" />
                ) : (
                  <Circle size={14} />
                )}
                <span className="agent-task-label">
                  <strong title={title}>{title}</strong>
                  <small>
                    {labels[agent.status] || "Unknown"}
                    {agent.message && agent.status !== "completed"
                      ? ` · ${agent.message.split("\n")[0].slice(0, 120)}`
                      : ""}
                  </small>
                </span>
                <ChevronDown size={14} />
              </summary>
              <div className="agent-task-detail">
                {agent.task && (
                  <>
                    <h3>Assigned task</h3>
                    <MessageContent>{agent.task}</MessageContent>
                  </>
                )}
                {agent.message && (
                  <>
                    <h3>
                      {agent.status === "completed"
                        ? "Result"
                        : "Latest update"}
                    </h3>
                    <MessageContent>{agent.message}</MessageContent>
                  </>
                )}
                {agent.logs && (
                  <details className="agent-tool-logs">
                    <summary>Tool output</summary>
                    {Object.entries(agent.logs).map(([id, log]) => (
                      <pre key={id}>{log}</pre>
                    ))}
                  </details>
                )}
                {!agent.task && !agent.message && (
                  <p className="muted">No details reported yet.</p>
                )}
              </div>
            </details>
          );
        })}
      </div>
    </section>
  );
}
