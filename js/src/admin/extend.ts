import app from 'flarum/admin/app';
import Admin from 'flarum/common/extenders/Admin';
import FieldsEditor from './FieldsEditor';

/*
 * `customSetting` with a plain function: core calls it with `this` bound to
 * the extension page, whose `setting()` returns the stream its Save button
 * saves. An arrow function would lose that `this` silently.
 */
export default [
  new Admin()
    .setting(() => ({
      setting: 'ernestdefoe-recipes.image_urls',
      type: 'boolean',
      label: app.translator.trans('ernestdefoe-recipes.admin.settings.image_urls_label'),
      help: app.translator.trans('ernestdefoe-recipes.admin.settings.image_urls_help'),
    }))
    .permission(
      () => ({
        icon: 'fas fa-camera',
        label: app.translator.trans('ernestdefoe-recipes.admin.permissions.upload_photos'),
        permission: 'ernestdefoe-recipes.uploadPhotos',
      }),
      'start'
    )
    .customSetting(function (this: any) {
      return m(FieldsEditor, { stream: this.setting('ernestdefoe-recipes.fields', '[]') });
    }),
];

declare const m: import('mithril').Static;
