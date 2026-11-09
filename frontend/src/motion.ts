import type { Router } from 'vue-router'

/**
 * The app's sense of life, on every page without each page asking for it:
 *
 * - a progress bar along the top that follows the page and its data
 * - each page arrives in its own way: the heading wipes in, cards and rows follow one by one
 * - figures count up, things further down arrive as you scroll to them
 * - cards lean towards the pointer, taps give a little burst
 * - coins and confetti for moments worth marking
 *
 * All of it steps aside when the device asks for reduced motion, and while printing.
 */

const calm = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches
const EASE = 'cubic-bezier(0.2, 0.7, 0.2, 1)'
const BOUNCE = 'cubic-bezier(0.34, 1.45, 0.64, 1)'

// --- The progress bar -------------------------------------------------------------------------

let bar: HTMLDivElement | null = null
let busy = 0
let value = 0
let trickle: ReturnType<typeof setInterval> | undefined
let hideTimer: ReturnType<typeof setTimeout> | undefined

function paintBar() {
  if (bar) bar.style.transform = `scaleX(${value})`
}
function barStart() {
  if (!bar) return
  clearTimeout(hideTimer)
  busy++
  if (trickle) return
  bar.style.opacity = '1'
  value = 0.08
  paintBar()
  trickle = setInterval(() => {
    value += (0.92 - value) * 0.12
    paintBar()
  }, 160)
}
function barDone() {
  if (!bar) return
  busy = Math.max(0, busy - 1)
  if (busy > 0) return
  clearInterval(trickle)
  trickle = undefined
  value = 1
  paintBar()
  hideTimer = setTimeout(() => {
    if (bar) bar.style.opacity = '0'
    hideTimer = setTimeout(() => {
      value = 0
      paintBar()
    }, 260)
  }, 180)
}
/** Called by the API client around each request (see api.ts) */
export const netStart = barStart
export const netDone = barDone

// --- Pages arriving ---------------------------------------------------------------------------

type Keyframes = Keyframe[]
const ENTRANCES: { frames: Keyframes; easing: string; origin?: string }[] = [
  { frames: [{ opacity: 0, transform: 'translateY(22px)' }, { opacity: 1, transform: 'none' }], easing: EASE },
  { frames: [{ opacity: 0, transform: 'scale(0.9)' }, { opacity: 1, transform: 'none' }], easing: BOUNCE },
  { frames: [{ opacity: 0, transform: 'translateX(-28px)' }, { opacity: 1, transform: 'none' }], easing: EASE },
  { frames: [{ opacity: 0, transform: 'perspective(800px) rotateX(-22deg) translateY(16px)' }, { opacity: 1, transform: 'none' }], easing: EASE, origin: 'top center' },
  { frames: [{ opacity: 0, transform: 'translateX(28px) scale(0.97)' }, { opacity: 1, transform: 'none' }], easing: EASE },
]
let entrance = ENTRANCES[0]
let freshUntil = 0

const BLOCKS = 'h1, .card, .rounded-2xl, .rounded-3xl, tbody tr, ul > li, ol > li, dl > div'
const FIGURES = '.figure, p.text-2xl.font-bold, p.text-3xl.font-bold, p.text-4xl.font-bold, p.text-5xl.font-bold'
const seen = new WeakSet<Element>()
const waiting = new Set<Element>()
let flushing = 0
let io: IntersectionObserver | null = null

function inPage(el: Element) {
  return !!el.closest('main, [data-motion]')
}
function skip(el: Element) {
  return !!el.closest('.reveal, .absolute, .fixed, aside, [role=dialog], [role=alertdialog], [aria-hidden=true], .no-motion') && !el.matches('tbody tr, ul > li')
}

function play(el: HTMLElement, delay: number, quick = false) {
  if (el.matches('h1') && !quick) {
    el.animate([{ clipPath: 'inset(-10% 100% -10% 0)', opacity: 0.2 }, { clipPath: 'inset(-10% -2% -10% 0)', opacity: 1 }], { duration: 700, easing: EASE, fill: 'backwards', delay })
    return
  }
  if (quick) {
    el.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 220, easing: 'ease-out', fill: 'backwards' })
    return
  }
  if (entrance.origin) el.style.transformOrigin = entrance.origin
  const anim = el.animate(entrance.frames, { duration: 560, easing: entrance.easing, fill: 'backwards', delay })
  if (entrance.origin) anim.onfinish = () => (el.style.transformOrigin = '')
}

/** "UGX 1,250,000" or "45%" counts up by itself; dates, words and text inside other elements don't */
const NUMBER = /^((?:[A-Z]{3})?[\s  ]*)([-−]?)(\d[\d,]*)(\.\d+)?(%?)$/
function countUp(el: HTMLElement, delay: number) {
  if (el.children.length || el.closest('.no-count')) return
  const original = el.textContent?.trim() ?? ''
  const m = original.match(NUMBER)
  if (!m) return
  const target = Number(m[3].replace(/,/g, ''))
  if (!target) return
  const write = (n: number) => `${m[1]}${m[2]}${n.toLocaleString('en-US')}${m[5]}`
  let last = write(0)
  el.textContent = last
  const start = performance.now() + delay
  const step = (now: number) => {
    // Vue changed the figure meanwhile: leave it alone
    if (el.textContent !== last) return
    const t = Math.max(0, Math.min(1, (now - start) / 950))
    if (t >= 1) {
      el.textContent = original
      return
    }
    last = write(Math.round(target * (1 - (1 - t) ** 4)))
    el.textContent = last
    requestAnimationFrame(step)
  }
  requestAnimationFrame(step)
}

function flush() {
  flushing = 0
  if (document.body.classList.contains('printing-report')) {
    waiting.clear()
    return
  }
  const fresh = performance.now() < freshUntil
  const vh = window.innerHeight
  let order = 0
  let rows = 0
  const items = [...waiting].sort((a, b) => (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1))
  waiting.clear()
  for (const el of items) {
    if (!el.isConnected || !(el instanceof HTMLElement)) continue
    const isRow = el.matches('tbody tr, ul > li, ol > li')
    if (isRow && ++rows > 24) continue
    const nestedInCard = !isRow && !el.matches('h1') && el.parentElement?.closest('[data-m]')
    if (nestedInCard) continue
    el.setAttribute('data-m', '')
    const top = el.getBoundingClientRect().top
    if (top > vh && fresh) {
      io?.observe(el)
      continue
    }
    if (fresh) play(el, Math.min(order++ * 60, 760))
    else play(el, 0, true)
  }
}

function collect(node: Node) {
  if (!(node instanceof Element) || calm()) return
  if (node.closest('[role=listbox], .no-motion')) return
  const add = (el: Element) => {
    if (!seen.has(el) && inPage(el) && !skip(el)) {
      seen.add(el)
      waiting.add(el)
    }
  }
  if (node.matches(BLOCKS)) add(node)
  node.querySelectorAll(BLOCKS).forEach(add)
  const fresh = performance.now() < freshUntil
  if (fresh) {
    const figures = node.matches(FIGURES) ? [node, ...node.querySelectorAll(FIGURES)] : [...node.querySelectorAll(FIGURES)]
    figures.forEach((f, i) => {
      if (!seen.has(f) && inPage(f) && f instanceof HTMLElement) {
        seen.add(f)
        countUp(f, 140 + i * 90)
      }
    })
  }
  if (waiting.size && !flushing) flushing = requestAnimationFrame(flush)
}

// --- Cards that lean, taps that burst ---------------------------------------------------------

let leaning: HTMLElement | null = null
function stopLean() {
  if (leaning) {
    leaning.style.transform = ''
    leaning.style.transition = 'transform 0.35s ease-out'
    leaning = null
  }
}
function lean(e: PointerEvent) {
  const card = (e.target as Element | null)?.closest?.('a.card, [data-tilt]') as HTMLElement | null
  if (card !== leaning) stopLean()
  if (!card) return
  leaning = card
  const r = card.getBoundingClientRect()
  const x = (e.clientX - r.left) / r.width - 0.5
  const y = (e.clientY - r.top) / r.height - 0.5
  card.style.transition = 'transform 0.08s linear'
  card.style.transform = `perspective(900px) rotateX(${(-y * 5).toFixed(2)}deg) rotateY(${(x * 6).toFixed(2)}deg) translateY(-3px)`
}

function burst(e: PointerEvent) {
  const hit = (e.target as Element | null)?.closest?.('button, a.card, a.btn, a[class*="btn-"]')
  if (!hit || (hit as HTMLButtonElement).disabled) return
  const dot = document.createElement('span')
  dot.setAttribute('aria-hidden', 'true')
  dot.style.cssText = `position:fixed;left:${e.clientX - 22}px;top:${e.clientY - 22}px;width:44px;height:44px;border-radius:50%;border:2px solid #eea90f;pointer-events:none;z-index:70`
  document.body.appendChild(dot)
  dot.animate([{ transform: 'scale(0.2)', opacity: 0.9 }, { transform: 'scale(1.6)', opacity: 0 }], { duration: 420, easing: 'ease-out' }).onfinish = () => dot.remove()
  hit.animate([{ transform: 'scale(1)' }, { transform: 'scale(0.965)' }, { transform: 'scale(1)' }], { duration: 170, easing: 'ease-out' })
}

// --- Coins and confetti -----------------------------------------------------------------------

interface Piece { x: number; y: number; vx: number; vy: number; spin: number; angle: number; size: number; color: string; coin: boolean }
const COLORS = ['#eea90f', '#f2b934', '#3a46c4', '#2b35af', '#0e8f6e', '#ffffff']

function shower(pieces: Piece[]) {
  if (calm() || !pieces.length) return
  const canvas = document.createElement('canvas')
  canvas.setAttribute('aria-hidden', 'true')
  canvas.width = window.innerWidth
  canvas.height = window.innerHeight
  canvas.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:80'
  document.body.appendChild(canvas)
  const ctx = canvas.getContext('2d')!
  const started = performance.now()
  const frame = (now: number) => {
    ctx.clearRect(0, 0, canvas.width, canvas.height)
    const age = now - started
    for (const p of pieces) {
      p.vy += 0.22
      p.x += p.vx
      p.y += p.vy
      p.angle += p.spin
      ctx.save()
      ctx.globalAlpha = Math.max(0, 1 - (age - 1500) / 700)
      ctx.translate(p.x, p.y)
      ctx.rotate(p.angle)
      if (p.coin) {
        ctx.scale(Math.abs(Math.cos(p.angle)) * 0.85 + 0.15, 1)
        ctx.fillStyle = '#eea90f'
        ctx.beginPath()
        ctx.arc(0, 0, p.size, 0, Math.PI * 2)
        ctx.fill()
        ctx.strokeStyle = '#c98a08'
        ctx.lineWidth = 1.5
        ctx.beginPath()
        ctx.arc(0, 0, p.size * 0.68, 0, Math.PI * 2)
        ctx.stroke()
      } else {
        ctx.fillStyle = p.color
        ctx.fillRect(-p.size / 2, -p.size / 3, p.size, p.size / 1.5)
      }
      ctx.restore()
    }
    if (age < 2200) requestAnimationFrame(frame)
    else canvas.remove()
  }
  requestAnimationFrame(frame)
}

/** Coins spring from a point: money came in */
export function coins(x = window.innerWidth / 2, y = window.innerHeight / 2, count = 22) {
  shower(Array.from({ length: count }, () => ({
    x, y,
    vx: (Math.random() - 0.5) * 11,
    vy: -5 - Math.random() * 9,
    spin: (Math.random() - 0.5) * 0.4,
    angle: Math.random() * 6,
    size: 5 + Math.random() * 5,
    color: '#eea90f',
    coin: true,
  })))
}

/** Confetti from the top: something worth celebrating is finished */
export function confetti(count = 130) {
  shower(Array.from({ length: count }, () => ({
    x: Math.random() * window.innerWidth,
    y: -20 - Math.random() * 240,
    vx: (Math.random() - 0.5) * 3,
    vy: 1 + Math.random() * 3,
    spin: (Math.random() - 0.5) * 0.3,
    angle: Math.random() * 6,
    size: 6 + Math.random() * 6,
    color: COLORS[Math.floor(Math.random() * COLORS.length)],
    coin: Math.random() < 0.15,
  })))
}

// --- Starting up ------------------------------------------------------------------------------

function hideSplash() {
  const splash = document.getElementById('splash')
  if (!splash) return
  const shownFor = performance.now()
  const wait = Math.max(0, 900 - shownFor)
  setTimeout(() => {
    if (calm()) {
      splash.remove()
      return
    }
    splash.animate([{ opacity: 1, transform: 'scale(1)' }, { opacity: 0, transform: 'scale(1.08)' }], { duration: 380, easing: 'ease-in', fill: 'forwards' }).onfinish = () => splash.remove()
  }, wait)
}

export function installMotion(router: Router) {
  bar = document.createElement('div')
  bar.className = 'route-bar'
  bar.setAttribute('aria-hidden', 'true')
  document.body.appendChild(bar)

  const nameHash = (s: string) => [...s].reduce((h, c) => (h * 31 + c.charCodeAt(0)) >>> 0, 7)
  router.beforeEach(() => {
    barStart()
  })
  router.afterEach((to) => {
    barDone()
    entrance = ENTRANCES[nameHash(String(to.name ?? to.path)) % ENTRANCES.length]
    // A page's data usually lands within this time, and arrives with the full show
    freshUntil = performance.now() + 2600
  })
  router.onError(() => barDone())
  router.isReady().then(hideSplash)

  if (!calm()) {
    io = new IntersectionObserver((entries) => {
      for (const e of entries) {
        if (e.isIntersecting) {
          io?.unobserve(e.target)
          const el = e.target as HTMLElement
          el.animate(entrance.frames, { duration: 480, easing: entrance.easing })
        }
      }
    }, { rootMargin: '0px 0px -6% 0px' })

    new MutationObserver((records) => {
      for (const r of records) r.addedNodes.forEach(collect)
    }).observe(document.body, { childList: true, subtree: true })

    if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
      document.addEventListener('pointermove', lean, { passive: true })
      document.addEventListener('pointerleave', stopLean)
    }
    document.addEventListener('pointerdown', burst, { passive: true })
  }
  // Nothing half-way through an animation on paper
  window.addEventListener('beforeprint', () => document.getAnimations().forEach((a) => a.finish()))
}
