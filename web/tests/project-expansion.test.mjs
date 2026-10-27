import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readProjectExpansion } from '../src/hooks/useProjectExpansion.ts';

test('project expansion restores saved states and tolerates invalid or unavailable storage', () => {
  const original = globalThis.localStorage;
  try {
    for (const [saved, expected] of [
      [null, { collapsed: [], projectsOpen: true }],
      ['{"collapsed":["project-a"],"projectsOpen":false}', { collapsed: ['project-a'], projectsOpen: false }],
      ['{"collapsed":[42,"project-b"]}', { collapsed: ['project-b'], projectsOpen: true }],
      ['broken json', { collapsed: [], projectsOpen: true }],
    ]) {
      globalThis.localStorage = { getItem: () => saved };
      assert.deepEqual(readProjectExpansion(), expected);
    }
    globalThis.localStorage = { getItem: () => { throw new Error('blocked'); } };
    assert.deepEqual(readProjectExpansion(), { collapsed: [], projectsOpen: true });
  } finally {
    globalThis.localStorage = original;
  }
});
