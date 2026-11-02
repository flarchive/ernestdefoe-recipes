import app from 'flarum/admin/app';
import { BUTTON, onScribe } from '../common/scribeButton';

/*
 * Scribe's toolbar builder learns the Recipe button here, so it can be placed.
 * The settings (the admin's recipe fields) are the `extend` export below.
 */
app.initializers.add('ernestdefoe/recipes', () => {
  onScribe((scribe) => scribe.registerButton?.({ ...BUTTON, run: () => {} }));
});

export { default as extend } from './extend';
