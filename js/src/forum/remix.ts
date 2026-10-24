import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';

/**
 * The Remix control on a card. What it does lives in ./remixAction, fetched on
 * the click: rebuilding a recipe's text is for members who press the button,
 * not for every reader of a recipe page.
 */

function t(key: string): string {
  return extractText(app.translator.trans(`ernestdefoe-recipes.forum.card.${key}`));
}

function remix(card: HTMLElement, post: any, where: 'reply' | 'discussion'): void {
  import('./remixAction').then((action) => action.remix(card, post, where));
}

/** The Remix control: one button, or a small menu when both kinds of variant are allowed. */
export function addRemix(card: HTMLElement, post: any): void {
  const header = card.querySelector<HTMLElement>('.Recipe-header');
  const discussion = post?.discussion?.();
  if (!header || !app.session.user || !discussion) return;

  const options: ('reply' | 'discussion')[] = [];
  if (discussion.canReply?.()) options.push('reply');
  if (app.forum.attribute('canStartDiscussion')) options.push('discussion');
  if (!options.length) return;

  const wrap = document.createElement('div');
  wrap.className = 'Recipe-remix';

  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'Button Button--flat Recipe-remixButton';
  button.title = t('remix_label');
  button.setAttribute('aria-haspopup', options.length > 1 ? 'menu' : 'false');
  button.innerHTML = '<i class="icon fas fa-code-branch" aria-hidden="true"></i> ';
  button.append(t('remix'));
  wrap.append(button);

  if (options.length === 1) {
    button.addEventListener('click', () => remix(card, post, options[0]));
  } else {
    const menu = document.createElement('div');
    menu.className = 'Recipe-remixMenu';
    menu.setAttribute('role', 'menu');
    menu.hidden = true;

    for (const where of options) {
      const item = document.createElement('button');
      item.type = 'button';
      item.setAttribute('role', 'menuitem');
      item.className = 'Recipe-remixItem';
      item.textContent = t(where === 'reply' ? 'remix_reply' : 'remix_discussion');
      item.addEventListener('click', () => remix(card, post, where));
      menu.append(item);
    }

    const toggle = (open: boolean) => {
      menu.hidden = !open;
      button.setAttribute('aria-expanded', String(open));
      if (open) document.addEventListener('click', outside, true);
      else document.removeEventListener('click', outside, true);
    };
    const outside = (e: Event) => {
      if (!wrap.contains(e.target as Node)) toggle(false);
    };
    button.addEventListener('click', () => toggle(menu.hidden));
    menu.querySelectorAll('button').forEach((item) => item.addEventListener('click', () => toggle(false)));
    wrap.append(menu);
  }

  header.append(wrap);
}
