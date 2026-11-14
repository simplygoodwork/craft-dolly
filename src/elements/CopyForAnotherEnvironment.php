<?php

namespace simplygoodwork\craftportage\elements;

use Craft;
use craft\base\ElementAction;
use simplygoodwork\craftportage\web\assets\export\ExportAsset;

/**
 * The entry index's bulk "Copy for another environment" action. It only appears for a user who can Export content, and
 * does nothing on the server: the browser asks the Portage export controller for the Payload of the selected entries.
 */
class CopyForAnotherEnvironment extends ElementAction
{
    public function getTriggerLabel(): string
    {
        return Craft::t('portage', 'Copy for another environment');
    }

    public function getTriggerHtml(): ?string
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(ExportAsset::class);
        $view->registerJsWithVars(fn($type) => <<<JS
(() => {
  new Craft.ElementActionTrigger({
    type: $type,
    // Only the canonical entries are exported, not the drafts or revisions an index can show.
    validateSelection: (selectedItems) => {
      for (let i = 0; i < selectedItems.length; i++) {
        const element = selectedItems.eq(i).find('.element');
        if (Garnish.hasAttr(element, 'data-draft-id') || Garnish.hasAttr(element, 'data-revision-id')) {
          return false;
        }
      }
      return true;
    },
    activate: (selectedItems, elementIndex) => {
      const ids = selectedItems.toArray().map((item) => $(item).find('.element:first').data('id'));
      Portage.exportEntries(ids, elementIndex.siteId);
    },
  });
})();
JS, [static::class]);

        return null;
    }
}
