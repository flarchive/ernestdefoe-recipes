import app from 'flarum/admin/app';
import { BUTTON, onScribe } from '../common/scribeButton';

/*
 * Only so Scribe's toolbar builder can offer the Recipe button for placing.
 * Recipes has no settings: what a recipe looks like is the post's business,
 * and how it scales is the reader's.
 */
app.initializers.add('ernestdefoe/recipes', () => {
  onScribe((scribe) => scribe.registerButton?.({ ...BUTTON, run: () => {} }));
});
