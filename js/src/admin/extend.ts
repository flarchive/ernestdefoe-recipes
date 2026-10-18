import Admin from 'flarum/common/extenders/Admin';
import FieldsEditor from './FieldsEditor';

/*
 * `customSetting` with a plain function: core calls it with `this` bound to
 * the extension page, whose `setting()` returns the stream its Save button
 * saves. An arrow function would lose that `this` silently.
 */
export default [
  new Admin().customSetting(function (this: any) {
    return m(FieldsEditor, { stream: this.setting('ernestdefoe-recipes.fields', '[]') });
  }),
];

declare const m: import('mithril').Static;
