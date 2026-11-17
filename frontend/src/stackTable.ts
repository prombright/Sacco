import type { Directive } from 'vue'

/**
 * v-stack on a <table>: on phones each row becomes a small card, every value labelled with its
 * column's heading (styles: .stack-table in style.css). The headings are copied from <thead> into
 * each cell's data-label, again whenever the rows change, so a table needs nothing else.
 *
 * v-stack="'plain'" keeps the first cell labelled too (when it isn't a name or a date).
 */
function label(table: HTMLTableElement) {
  const head = table.tHead?.rows[table.tHead.rows.length - 1]
  if (!head) return
  // Each column's heading, spreading headings that span several columns
  const names: string[] = []
  for (const th of Array.from(head.cells)) {
    const text = (th.textContent ?? '').replace(/\s+/g, ' ').trim()
    for (let i = 0; i < th.colSpan; i++) names.push(text)
  }
  for (const body of [...Array.from(table.tBodies), ...(table.tFoot ? [table.tFoot] : [])]) {
    for (const row of Array.from(body.rows)) {
      let col = 0
      for (const cell of Array.from(row.cells)) {
        // A cell spanning the whole row (a month heading, a note) keeps no label
        const name = cell.colSpan >= names.length ? '' : names[col] ?? ''
        if (cell.dataset.label !== name) cell.dataset.label = name
        col += cell.colSpan
      }
    }
  }
}

export const stack: Directive<HTMLTableElement, string | undefined> = {
  mounted(el, binding) {
    el.classList.add('stack-table')
    if (binding.value === 'plain') el.classList.add('stack-plain')
    label(el)
  },
  updated: (el) => label(el),
}
