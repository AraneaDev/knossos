/**
 * Where a hover card goes. The one rule for every host, so it imports nothing
 * and uses only erasable types: the web server serves this very file to the
 * page as JavaScript.
 */

/**
 * Where a hover card goes for a segment at `row` and `column` of a pane: under
 * its row, or over it when the rows below cannot hold the card and the rows
 * above can; never past the pane's right edge. In cells, so Claude Code and a
 * page place a card by the one rule.
 */
export function cardPlace(row: number, column: number, card: { rows: number; columns: number }, pane: { rows: number; columns: number }): { row: number; column: number } {
  const below = row + 1 + card.rows <= pane.rows || row < card.rows
  return { row: below ? row + 1 : row - card.rows, column: Math.max(0, Math.min(column, pane.columns - card.columns)) }
}
