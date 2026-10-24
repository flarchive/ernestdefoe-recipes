import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import { format, isPlural, matchCase, measure } from '../common/amount';
import { addRemix } from './remix';
import { related } from './related';

/**
 * What a READER can do with a recipe card: change the servings, start a timer,
 * print it. Everything else on the card is server-rendered HTML, which is what
 * a reader with no JavaScript, an email and a search engine all get.
 */

const READY = 'recipeReady';
const MAX_SERVINGS = 999;

function t(key: string, params?: Record<string, string>): string {
  return extractText(app.translator.trans(`ernestdefoe-recipes.forum.card.${key}`, params ?? {}));
}

/**
 * Take over every recipe card inside a piece of rendered content.
 *
 * 🚨 Must also be called for content that arrives without a page load — a reply
 * that appears live, a post edited in place. index.ts calls it from
 * CommentPost's refreshContent, which runs on create AND update.
 */
export default function decorate(root: HTMLElement | null | undefined, post?: any): void {
  if (!root) return;

  root.querySelectorAll<HTMLElement>('.Recipe:not(.Recipe--source)').forEach((card, index) => {
    if (card.dataset[READY]) return;
    card.dataset[READY] = '1';

    // First, while the lists still hold exactly the text the server wrote.
    addChecks(card, post ? `${post.id()}:${index}` : null);
    addPrint(card);
    localiseFacts(card);
    if (post) {
      addRemix(card, post);
      related(card, Number(post.id()));
    }
    addServings(card);
    card.querySelectorAll<HTMLElement>('.Recipe-timer[data-seconds]').forEach(addTimer);
  });
}

/* ───────────────────────────── ticks ───────────────────────────── */

/**
 * A checkbox on every ingredient and step, to keep your place while cooking.
 *
 * Kept in this browser only (nothing is sent anywhere, so guests get it too),
 * per post and card, by POSITION among the ingredients or steps: scaling the
 * servings rewrites an amount, never which line it is. Forgotten after a day
 * without a tick, and as soon as the recipe's lists change, when a position
 * would point at a different line.
 */
const TICKS = 'recipes-ticks:';
const TICKS_TTL = 24 * 3600 * 1000;

interface Ticks {
  h: number;
  t: number;
  i: number[];
  s: number[];
}

function store(): Storage | null {
  try {
    return window.localStorage;
  } catch {
    return null;
  }
}

/** A cheap fingerprint of the lists' text: an edited recipe starts unticked. */
function fingerprint(card: HTMLElement): number {
  let hash = 5381;
  const text = Array.from(card.querySelectorAll('.Recipe-list'), (list) => list.textContent).join('\n');
  for (let i = 0; i < text.length; i++) hash = ((hash << 5) + hash + text.charCodeAt(i)) | 0;
  return hash;
}

let swept = false;

/** Once per page: drop every expired card's ticks, so old recipes leave nothing behind. */
function sweep(storage: Storage): void {
  if (swept) return;
  swept = true;
  try {
    for (let i = storage.length - 1; i >= 0; i--) {
      const key = storage.key(i);
      if (!key?.startsWith(TICKS)) continue;
      const saved = JSON.parse(storage.getItem(key) || 'null');
      if (!saved || Date.now() - saved.t > TICKS_TTL) storage.removeItem(key);
    }
  } catch {}
}

function addChecks(card: HTMLElement, id: string | null): void {
  const kinds = { i: card.querySelectorAll<HTMLElement>('.Recipe-ingredient'), s: card.querySelectorAll<HTMLElement>('.Recipe-step') };
  if (!kinds.i.length && !kinds.s.length) return;

  const storage = id ? store() : null;
  const key = TICKS + id;
  const hash = fingerprint(card);
  let saved: Ticks | null = null;

  if (storage) {
    sweep(storage);
    try {
      saved = JSON.parse(storage.getItem(key) || 'null');
    } catch {}
    if (saved && (saved.h !== hash || Date.now() - saved.t > TICKS_TTL)) saved = null;
  }

  const boxes: HTMLInputElement[] = [];
  const reset = document.createElement('button');
  reset.type = 'button';
  reset.className = 'Button Button--link Recipe-untick';
  reset.textContent = t('untick');
  reset.title = t('untick_label');

  const save = () => {
    const ticks: Ticks = { h: hash, t: Date.now(), i: [], s: [] };
    boxes.forEach((box) => box.checked && ticks[box.dataset.kind as 'i' | 's'].push(Number(box.dataset.index)));
    reset.hidden = !ticks.i.length && !ticks.s.length;
    if (!storage) return;
    try {
      if (reset.hidden) storage.removeItem(key);
      else storage.setItem(key, JSON.stringify(ticks));
    } catch {
      // Full or blocked: the ticks still work until the page is left.
    }
  };

  (Object.keys(kinds) as ('i' | 's')[]).forEach((kind) => {
    kinds[kind].forEach((item, index) => {
      const box = document.createElement('input');
      box.type = 'checkbox';
      box.className = 'Recipe-check';
      box.dataset.kind = kind;
      box.dataset.index = String(index);
      box.checked = !!saved?.[kind]?.includes(index);

      // The row is the label: a tap anywhere on the line ticks it. A timer
      // inside stays its own button (a label never activates on one).
      const label = document.createElement('label');
      label.className = 'Recipe-checkLabel';
      const text = document.createElement('span');
      text.className = 'Recipe-checkText';
      text.append(...Array.from(item.childNodes));
      label.append(box, text);
      item.append(label);

      const mark = () => item.classList.toggle('is-done', box.checked);
      box.addEventListener('change', () => {
        mark();
        save();
      });
      mark();
      boxes.push(box);
    });
  });

  reset.addEventListener('click', () => {
    boxes.forEach((box) => {
      box.checked = false;
      box.dispatchEvent(new Event('change'));
    });
  });
  reset.hidden = !boxes.some((box) => box.checked);
  card.querySelector('.Recipe-sectionTitle')?.append(reset);
}

/* ───────────────────────────── fields ───────────────────────────── */

/** "12.5" → "12,5" for a German reader; the server writes the plain number. */
function localiseFacts(card: HTMLElement): void {
  const locale = app.translator.getLocale?.() || undefined;
  card.querySelectorAll<HTMLElement>('.Recipe-factValue').forEach((value) => {
    const q = parseFloat(value.dataset.q || '');
    if (Number.isFinite(q)) value.textContent = measure(q, locale);
  });
}

/* ───────────────────────────── servings ───────────────────────────── */

function addServings(card: HTMLElement): void {
  const base = parseInt(card.dataset.servings || '', 10);
  const slot = card.querySelector<HTMLElement>('.Recipe-servings');
  if (!slot || !Number.isFinite(base) || base < 1) return;

  let current = base;

  const value = document.createElement('output');
  value.className = 'Recipe-servingsValue';
  value.setAttribute('aria-live', 'polite');

  const button = (icon: string, label: string, delta: number) => {
    const el = document.createElement('button');
    el.type = 'button';
    el.className = 'Button Button--icon Button--flat Recipe-step-' + (delta > 0 ? 'up' : 'down');
    el.setAttribute('aria-label', label);
    el.title = label;
    el.innerHTML = `<i class="icon fas fa-${icon}" aria-hidden="true"></i>`;
    el.addEventListener('click', () => set(current + delta));
    return el;
  };

  const down = button('minus', t('fewer'), -1);
  const up = button('plus', t('more'), 1);

  const reset = document.createElement('button');
  reset.type = 'button';
  reset.className = 'Button Button--link Recipe-reset';
  reset.textContent = '↺';
  reset.title = t('reset');
  reset.setAttribute('aria-label', t('reset'));
  reset.addEventListener('click', () => set(base));

  const set = (n: number) => {
    current = Math.min(MAX_SERVINGS, Math.max(1, n));
    value.textContent = String(current);
    down.disabled = current <= 1;
    up.disabled = current >= MAX_SERVINGS;
    reset.hidden = current === base;
    card.classList.toggle('Recipe--scaled', current !== base);
    scale(card, current / base);
  };

  slot.replaceChildren(down, value, up, reset);
  slot.classList.add('Recipe-servings--interactive');
  set(base);
}

function scale(card: HTMLElement, factor: number): void {
  const locale = app.translator.getLocale?.() || undefined;

  // The admin's fields: whole-recipe values follow the servings, per-serving ones do not.
  card.querySelectorAll<HTMLElement>('.Recipe-factValue[data-scale]').forEach((value) => {
    const q = parseFloat(value.dataset.q || '');
    if (Number.isFinite(q)) value.textContent = measure(q * factor, locale);
  });

  card.querySelectorAll<HTMLElement>('.Recipe-ingredient[data-q]').forEach((item) => {
    const qty = item.querySelector<HTMLElement>('.Recipe-qty');
    if (!qty) return;

    // The author's own text, kept for when the reader scales back to 1×.
    qty.dataset.original ??= qty.textContent || '';

    const q = parseFloat(item.dataset.q || '');
    const q2 = item.dataset.q2 ? parseFloat(item.dataset.q2) : null;
    const decimal = item.dataset.dec === '1';
    const unit = item.querySelector<HTMLElement>('.Recipe-unit');

    if (unit) unit.dataset.original ??= unit.textContent || '';

    if (factor === 1) {
      qty.textContent = qty.dataset.original;
      if (unit) unit.textContent = unit.dataset.original!;
      return;
    }

    const a = q * factor;
    const b = q2 === null ? null : q2 * factor;
    qty.textContent = format(a, decimal, locale) + (b === null ? '' : '–' + format(b, decimal, locale));

    if (unit && item.dataset.one && item.dataset.many) {
      const word = isPlural(a, b) ? item.dataset.many : item.dataset.one;
      const original = unit.dataset.original!;
      unit.textContent = matchCase(word, original) + (original.endsWith('.') ? '.' : '');
    }
  });
}

/* ───────────────────────────── timers ───────────────────────────── */

let audio: AudioContext | null = null;

/**
 * Three short beeps, made rather than downloaded — a sound file would be one
 * more request on every page with a recipe, for a sound most readers never hear.
 */
function beep(): void {
  try {
    audio ??= new AudioContext();
    for (let i = 0; i < 3; i++) {
      const osc = audio.createOscillator();
      const gain = audio.createGain();
      const at = audio.currentTime + i * 0.35;
      osc.frequency.value = 880;
      gain.gain.setValueAtTime(0.0001, at);
      gain.gain.exponentialRampToValueAtTime(0.3, at + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, at + 0.25);
      osc.connect(gain).connect(audio.destination);
      osc.start(at);
      osc.stop(at + 0.3);
    }
  } catch {
    // No audio is no reason to lose the visual alert.
  }

  navigator.vibrate?.([200, 100, 200]);
}

function clock(seconds: number): string {
  const s = Math.max(0, Math.ceil(seconds));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const pad = (n: number) => String(n).padStart(2, '0');

  return (h ? `${h}:${pad(m)}` : String(m)) + ':' + pad(s % 60);
}

/**
 * The server renders a timer as a SPAN, because without JavaScript it cannot
 * do anything and must not look as if it could. Here it becomes the button.
 *
 * One click starts it, the next pauses, the next resumes. When it runs out it
 * beeps, shakes the phone and stays lit until it is clicked, which resets it.
 */
function addTimer(span: HTMLElement): void {
  const total = parseInt(span.dataset.seconds || '', 10);
  if (!Number.isFinite(total) || total <= 0) return;

  const label = span.textContent || '';
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'Recipe-timer Recipe-timer--button';
  button.dataset.seconds = String(total);

  const icon = document.createElement('i');
  icon.className = 'icon far fa-clock';
  icon.setAttribute('aria-hidden', 'true');
  const text = document.createElement('span');
  text.className = 'Recipe-timerText';
  button.append(icon, text);

  let state: 'idle' | 'running' | 'paused' | 'done' = 'idle';
  let remaining = total;
  let endsAt = 0;
  let tick = 0;

  const render = () => {
    button.dataset.state = state;
    text.textContent = state === 'idle' ? label : state === 'done' ? t('timer_done') : clock(remaining);
    const aria = { idle: t('timer_start', { time: label }), running: t('timer_pause'), paused: t('timer_resume'), done: t('timer_reset') }[state];
    button.setAttribute('aria-label', aria);
    button.title = aria;
  };

  const stop = () => {
    window.clearInterval(tick);
    tick = 0;
  };

  const run = () => {
    // Measured against the clock, not counted down: a background tab's
    // intervals are throttled to a crawl, and a timer that ran slow while you
    // were in another tab would burn the dinner.
    remaining = (endsAt - Date.now()) / 1000;
    if (remaining <= 0) {
      stop();
      state = 'done';
      beep();
    }
    // A card that has left the page (a post edited, a thread changed) takes its timer with it.
    if (!button.isConnected) stop();
    render();
  };

  button.addEventListener('click', () => {
    if (state === 'idle' || state === 'paused') {
      endsAt = Date.now() + remaining * 1000;
      state = 'running';
      tick = window.setInterval(run, 250);
      // Created inside the click, so the browser allows it to make a sound later.
      try {
        audio ??= new AudioContext();
      } catch {}
    } else if (state === 'running') {
      stop();
      remaining = (endsAt - Date.now()) / 1000;
      state = 'paused';
    } else {
      remaining = total;
      state = 'idle';
    }
    render();
  });

  render();
  span.replaceWith(button);
}

/* ───────────────────────────── print ───────────────────────────── */

function addPrint(card: HTMLElement): void {
  const header = card.querySelector<HTMLElement>('.Recipe-header');
  if (!header) return;

  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'Button Button--icon Button--flat Recipe-print';
  button.title = t('print_label');
  button.setAttribute('aria-label', t('print_label'));
  button.innerHTML = '<i class="icon fas fa-print" aria-hidden="true"></i>';
  button.addEventListener('click', () => print(card));

  header.prepend(button);
}

/**
 * Print this recipe and nothing else.
 *
 * A copy goes into a container of its own at the top of <body>, and the print
 * stylesheet hides every other child of <body>. Hiding the rest with
 * `visibility` instead would print blank pages for the whole thread beneath
 * it; printing the card where it sits would print the forum around it.
 *
 * The copy is taken NOW, so it carries the servings the reader picked — a
 * recipe doubled on screen is doubled on paper.
 */
function print(card: HTMLElement): void {
  const root = document.createElement('div');
  root.className = 'Recipe-printRoot';

  const copy = card.cloneNode(true) as HTMLElement;
  copy.classList.add('Recipe--print');
  // Paper gets the whole recipe, not the place you had reached in it.
  copy.querySelectorAll('.is-done').forEach((item) => item.classList.remove('is-done'));
  root.appendChild(copy);

  const source = document.createElement('p');
  source.className = 'Recipe-printSource';
  source.textContent = window.location.href;
  root.appendChild(source);

  document.body.appendChild(root);
  document.body.classList.add('Recipe-printing');

  const done = () => {
    root.remove();
    document.body.classList.remove('Recipe-printing');
    window.removeEventListener('afterprint', done);
  };
  window.addEventListener('afterprint', done);

  // `afterprint` fires in every current browser whether print() blocks or not;
  // removing the copy on a timer instead could pull it out from under a print
  // preview that is still open.
  window.print();
}
