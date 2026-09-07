// The cheat-sheet is generated from keywords.json (§2.14) — the same table both parsers read, so it can never drift
// from the grammar. Every example inserts into the focused Rx line and the "try it" box shows the live parse.
import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { KEYWORDS } from '@panel/lib/prescription/shorthand/keywords';
import { Cheatsheet } from '../Cheatsheet';

vi.mock('@panel/api/prescription', () => ({
  fetchShorthandHelp: vi.fn(async () => {
    throw new Error('offline'); // the bundled table is the fallback and must render on its own
  }),
}));

describe('Cheatsheet', () => {
  it('renders every documented example straight from keywords.json', async () => {
    render(<Cheatsheet open lang="en" onClose={() => undefined} onInsert={() => undefined} />);
    for (const example of KEYWORDS.examples.filter((e) => ['schedule', 'duration', 'timing', 'route', 'quantity', 'instruction'].includes(e.group))) {
      expect(await screen.findByText(example.input)).toBeInTheDocument();
    }
  });

  it('shows the live parse of the try-it box and updates as it is typed', async () => {
    render(<Cheatsheet open lang="en" onClose={() => undefined} onInsert={() => undefined} />);
    expect(screen.getByTestId('cheatsheet-interpretation')).toHaveTextContent('1 + 0 + 1 tab · 10 days · after meal · 20 tab');

    fireEvent.change(screen.getByLabelText('Try it'), { target: { value: '1 tds 5d' } });
    await waitFor(() => expect(screen.getByTestId('cheatsheet-interpretation')).toHaveTextContent('three times daily'));

    fireEvent.change(screen.getByLabelText('Try it'), { target: { value: '1+0+1 10d aff' } });
    await waitFor(() => expect(screen.getByText(/did you mean "af"/i)).toBeInTheDocument());
  });

  it('inserts a tapped example into the focused Rx line', async () => {
    const insert = vi.fn();
    render(<Cheatsheet open lang="en" onClose={() => undefined} onInsert={insert} />);
    fireEvent.click(await screen.findByText('1 tds 5d'));
    expect(insert).toHaveBeenCalledWith('1 tds 5d');
  });

  it('renders the Bangla explanations when the pad language is Bangla', async () => {
    render(<Cheatsheet open lang="bn" onClose={() => undefined} onInsert={() => undefined} />);
    const example = KEYWORDS.examples[0];
    expect(await screen.findByText(example?.bn ?? '')).toBeInTheDocument();
  });
});
