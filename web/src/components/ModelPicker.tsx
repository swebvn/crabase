import { Check, ChevronDown } from 'lucide-react';
import type { CodexModel } from '../types';
import { Menu } from './ui';
import { reasoningLevels } from '../lib/models';

export function ModelPicker({ models, chosen, reasoning, unavailable, selectModel, selectReasoning }: {
  models: CodexModel[];
  chosen?: CodexModel;
  reasoning: string;
  unavailable: boolean;
  selectModel: (model: string) => void;
  selectReasoning: (effort: string) => void;
}) {
  const label = chosen?.displayName || (unavailable ? 'Models unavailable' : 'Loading models…');
  const levels = reasoningLevels(chosen);
  return <Menu className="model-picker" contentRole="dialog" disabled={!models.length}
    label={`Model and reasoning: ${label}${reasoning ? `, ${reasoning}` : ''}`}
    icon={<><span className="truncate">{label}</span>{reasoning && <span className="model-picker-effort">{reasoning}</span>}<ChevronDown size={12} /></>}>
    <div className="model-picker-list" role="group" aria-label="Model">
      {models.map(model => <button key={model.model} aria-pressed={chosen?.model === model.model}
        onClick={() => selectModel(model.model)}><span>{model.displayName}</span>{chosen?.model === model.model && <Check size={14} />}</button>)}
    </div>
    <div className="model-picker-reasoning" role="group" aria-label="Reasoning level">
      {levels.length ? levels.map(({ reasoningEffort: effort }) => <button key={effort}
        aria-pressed={reasoning === effort} onClick={() => selectReasoning(effort)}>
        {effort[0].toUpperCase() + effort.slice(1)}
      </button>) : <span>No reasoning levels</span>}
    </div>
  </Menu>;
}
