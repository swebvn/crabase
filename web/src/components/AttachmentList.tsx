import { useState } from 'react';
import { Download, File, RotateCcw, X } from 'lucide-react';
import type { Artifact } from '../types';
import { Dialog, IconButton, IconLink } from './ui';

type Attachment = Artifact & { key?: string; progress?: number; error?: string };
export function AttachmentList<T extends Attachment>({ files, remove, retry, disabled }: {
  files: T[];
  remove?: (file: T) => void;
  retry?: (file: T) => void;
  disabled?: boolean;
}) {
  const [preview, setPreview] = useState<Artifact>();
  if (!files.length) return null;
  return <>
    <div className="attachment-list" aria-label="Attachments">
      {files.map(file => {
        const image = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif'].includes(file.mime);
        if (image && !remove) return <button key={file.key || file.url} className="artifact-preview" aria-label={`Preview ${file.name}`} onClick={() => setPreview(file)}><img src={file.url} alt={file.name} loading="lazy" /></button>;
        if (image && remove) return <div className="attachment-image-draft" key={file.key || file.url}>
          <button className="attachment-thumbnail" aria-label={`Preview ${file.name}`} onClick={() => setPreview(file)}><img src={file.url} alt="" /></button>
          <IconButton label={`Remove ${file.name}`} disabled={disabled} onClick={() => remove(file)}><X size={12} /></IconButton>
          {file.error && <span className="attachment-image-error" role="alert">{file.error}{retry && <IconButton label={`Retry ${file.name}`} disabled={disabled} onClick={() => retry(file)}><RotateCcw size={14} /></IconButton>}</span>}
        </div>;
        return <div className="attachment-chip" key={file.key || file.url}>
        <File size={18} aria-hidden="true" />
        <div className="attachment-info">
          <span className="truncate" title={file.name}>{file.name}</span>
          <span className="attachment-meta">{file.size < 1024 ? `${file.size} B` : file.size < 1048576 ? `${Math.ceil(file.size / 1024)} KB` : `${(file.size / 1048576).toFixed(1)} MB`}
            {file.progress !== undefined && file.progress < 100 && !file.error && <span role="status"> · {file.progress}%</span>}
          </span>
          {file.error && <span className="attachment-error" role="alert">{file.error}</span>}
        </div>
        {file.error && retry && <IconButton label={`Retry ${file.name}`} disabled={disabled} onClick={() => retry(file)}><RotateCcw size={14} /></IconButton>}
        {remove ? <IconButton label={`Remove ${file.name}`} disabled={disabled} onClick={() => remove(file)}><X size={14} /></IconButton> :
          <IconLink href={`${file.url}?download=1`} label={`Download ${file.name}`}><Download size={14} /></IconLink>}
      </div>;
      })}
    </div>
    {preview && <Dialog className="image-lightbox" title={preview.name} close={() => setPreview(undefined)}><img src={preview.url} alt={preview.name} /></Dialog>}
  </>;
}
