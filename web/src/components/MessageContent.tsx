import { useState } from "react";
import Markdown from "react-markdown";
import remarkGfm from "remark-gfm";
import { Download } from "lucide-react";
import { isArtifactUrl, isImageArtifact } from "../lib/artifacts";
import { Dialog } from "./ui";

export function MessageContent({ children, onFileMention }: { children: string; onFileMention?: (path: string) => void }) {
  const [preview, setPreview] = useState<{ src: string; alt: string }>();
  return (
    <>
      <Markdown
        remarkPlugins={[remarkGfm]}
        components={{
          img: ({ src, alt = "" }) => src ? (
            <button
              className="artifact-preview"
              aria-label={`Preview ${alt || "image"}`}
              onClick={() => setPreview({ src, alt })}
            >
              <img src={src} alt={alt} loading="lazy" />
            </button>
          ) : null,
          a: ({ href, children }) =>
            href?.startsWith("#crabase-file:") ? <button type="button" className="file-mention-link" onClick={(event) => { event.preventDefault(); event.stopPropagation(); onFileMention?.(decodeURIComponent(href.slice(14))); }}>{children}</button> :
            href && isArtifactUrl(href) ? (
              <span className="artifact-link">
                {isImageArtifact(href) && typeof children === "string" && (
                  <button
                    className="artifact-preview"
                    aria-label={`Preview ${children}`}
                    onClick={() => setPreview({ src: href, alt: children })}
                  >
                    <img src={href} alt={children} loading="lazy" />
                  </button>
                )}
                <a href={`${href}?download=1`}>
                  <Download size={14} />
                  {children}
                </a>
              </span>
            ) : href?.startsWith("#") ? (
              <a href={href}>{children}</a>
            ) : (
              // Links in messages always open in a new tab so the workspace stays open.
              <a href={href} target="_blank" rel="noopener noreferrer">{children}</a>
            ),
        }}
      >
        {children.replace(/@([\w./-]+)/g, (_, path) => `[@${path}](#crabase-file:${encodeURIComponent(path)})`)}
      </Markdown>
      {preview && (
        <Dialog
          className="image-lightbox"
          title={preview.alt || "Image preview"}
          close={() => setPreview(undefined)}
        >
          <img src={preview.src} alt={preview.alt} />
        </Dialog>
      )}
    </>
  );
}
