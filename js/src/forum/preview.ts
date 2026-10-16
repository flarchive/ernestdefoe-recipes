import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import { parse } from '../common/source';

/**
 * The card as the composer preview shows it.
 *
 * The preview is rendered by s9e's JavaScript alone; the server's Render never
 * sees it. So this draws an approximation from the same text: the title, the
 * times as written, the lists with their groups and timers. Amounts are not
 * read here and do not scale — the post does that once it is saved.
 *
 * 🚨 Called by s9e from the template's `data-s9e-livepreview-onrender`, on the
 * NEW element, synchronously, before the diff. It must not be async: whatever
 * it has not done by the time it returns, the diff throws away.
 */

function label(key: string): string {
  return extractText(app.translator.trans(`ernestdefoe-recipes.forum.${key}`));
}

function el<K extends keyof HTMLElementTagNameMap>(tag: K, className: string, text?: string): HTMLElementTagNameMap[K] {
  const node = document.createElement(tag);
  node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function list(kind: 'ingredients' | 'steps', lines: string): HTMLElement | null {
  const items = lines.split('\n').filter(Boolean);
  if (!items.length) return null;

  const section = el('section', `Recipe-section Recipe-section--${kind}`);
  section.append(el('h4', 'Recipe-sectionTitle', label(kind)));
  const ul = el('ul', `Recipe-list Recipe-list--${kind}`);

  for (let line of items) {
    line = line.replace(kind === 'steps' ? /^(?:[-*•+]|\d{1,3}[.)])\s+/ : /^[-*•+]\s+/, '');

    if (line.endsWith(':') && line.length <= 80) {
      ul.append(el('li', 'Recipe-group', line.slice(0, -1).trim()));
      continue;
    }

    const li = el('li', kind === 'steps' ? 'Recipe-step' : 'Recipe-ingredient');

    if (kind === 'steps') {
      // [25m] shown as the timer it will become, without the clock.
      line.split(/(\[[0-9hms ]{1,12}\])/i).forEach((part, i) => {
        if (i % 2) li.append(el('span', 'Recipe-timer', part.slice(1, -1)));
        else if (part) li.append(part);
      });
    } else {
      li.textContent = line;
    }

    ul.append(li);
  }

  section.append(ul);
  return section;
}

export default function preview(source: HTMLElement): void {
  const fields = parse(source.textContent || '');
  const card = source.parentElement;

  const header = el('div', 'Recipe-header');
  if (fields.title) header.append(el('h3', 'Recipe-title', fields.title));

  const meta = el('dl', 'Recipe-meta');
  for (const key of ['servings', 'prep', 'cook'] as const) {
    if (!fields[key]) continue;
    const item = el('div', `Recipe-metaItem Recipe-metaItem--${key}`);
    item.append(el('dt', '', label(key)), el('dd', `Recipe-${key}`, fields[key]));
    meta.append(item);
  }
  if (meta.childElementCount) header.append(meta);

  const parts: (HTMLElement | null)[] = [header];
  if (fields.description) parts.push(el('div', 'Recipe-description', fields.description));
  parts.push(list('ingredients', fields.ingredients), list('steps', fields.steps));

  source.replaceChildren(...parts.filter((part): part is HTMLElement => part !== null));
  source.className = 'Recipe-source Recipe-source--card';
  card?.classList.add('Recipe--preview');
}
