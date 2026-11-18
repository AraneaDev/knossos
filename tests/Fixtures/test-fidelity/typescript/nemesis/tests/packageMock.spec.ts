import { describe, it, vi } from 'vitest';

// A double bound to a genuine third-party package: nothing this scan reads
// declares 'node:fs', so this stays unknowable rather than unresolved or
// checked, whatever local repository classes happen to share a name with a
// JavaScript or Node global elsewhere in the corpus.
import * as fs from 'node:fs';

describe('a double bound to a real package import', () => {
  it('is not something this scan owns', () => {
    vi.spyOn(fs, 'readFileSync').mockReturnValue('mocked' as unknown as Buffer);
  });
});
