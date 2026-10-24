/**
 * The Recipe button as Scribe's toolbar knows it. Shared by both bundles: the
 * forum gives it something to do, the admin only needs Scribe's toolbar
 * builder to be able to offer it for placing.
 */

declare const flarum: { reg?: { onLoad(namespace: string, id: string, handler: (module: any) => void): void } };

export const NODE = 'ernestdefoeRecipe';

export const BUTTON = {
  key: NODE,
  icon: 'fas fa-utensils',
  label: 'recipe',
  translationKey: 'ernestdefoe-recipes.lib.composer.button',
};

export function onScribe(handler: (scribe: any) => void): void {
  if (typeof flarum === 'undefined' || !flarum.reg) return;

  flarum.reg.onLoad('ernestdefoe-scribe', 'registry', handler);
}
