import { Download, File } from "lucide-react";
import { AgentActivity } from "./AgentActivity";
import type { Artifact, Message, Project } from "../types";
import { RightPanel } from "./RightPanel";
export function DetailsPanel({
  artifacts,
  messages,
  project,
  chatSelected,
  loaded,
  close,
  open,
  embedded = false,
}: {
  artifacts: Artifact[];
  messages: Message[];
  project?: Project;
  chatSelected: boolean;
  loaded: boolean;
  close: () => void;
  open: boolean;
  embedded?: boolean;
}) {
  return (
    <RightPanel className="details-panel" label={project ? "Project and chat details" : "Artifacts and agent activity"}
      title={project?.name || "Artifacts"} {...{ open, close }} defaultWidth={280} minWidth={240} maxWidth={500}
      storageKey="crabase-details-width" embedded={embedded}>
      {chatSelected && (
        <div>
          {!loaded ? (
            <p className="muted">Loading artifacts…</p>
          ) : !artifacts.length ? (
            <p className="muted">No artifacts yet.</p>
          ) : (
            <ul className="artifact-list">
              {artifacts.map((file) => (
                <li key={file.url}>
                  {/^image\/(png|jpeg|gif|webp|avif)$/.test(file.mime) ? (
                    <a
                      href={file.url}
                      target="_blank"
                      rel="noreferrer"
                      aria-label={`Preview ${file.name}`}
                    >
                      <img src={file.url} alt="" loading="lazy" />
                    </a>
                  ) : (
                    <File size={16} aria-hidden="true" />
                  )}
                  <a
                    href={`${file.url}?download=1`}
                    className="artifact-download"
                    title={file.name}
                  >
                    <span>
                      <strong>{file.name.replace(/^[a-f0-9]{12}-/, "")}</strong>
                      <small>
                        {file.name.split(".").pop()?.toUpperCase()} ·{" "}
                        {file.size < 1024
                          ? `${file.size} B`
                          : file.size < 1048576
                            ? `${(file.size / 1024).toFixed(1)} KB`
                            : `${(file.size / 1048576).toFixed(1)} MB`}
                      </small>
                    </span>
                    <Download size={14} aria-label="Download" />
                  </a>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
      {chatSelected && loaded && messages
        .filter((message) => message.role === "agent_activity")
        .map((message) => <AgentActivity key={message.id} body={message.body} />)}
    </RightPanel>
  );
}
