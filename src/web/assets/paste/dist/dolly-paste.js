/* global Craft, Garnish, $ */
(function () {
  const t = (message, params) => Craft.t('dolly', message, params);

  /**
   * Returns the Matrix or Neo blocks of a field in the order they're shown, which is the order of the field's value. Only
   * the field's own blocks: the blocks of a field nested in one of them belong to that field.
   */
  function blocksOf($field) {
    return $field
      .find('.nested-element-cards .card, .matrixblock, .ni_block')
      .filter((i, el) => $(el).parent().closest('.field')[0] === $field[0]);
  }

  /**
   * The ID the page shows a block with, which the server checks against the block at the same position in the draft.
   */
  function blockId(block) {
    const $block = $(block);

    return parseInt($block.attr('data-id') || $block.attr('data-neo-b-id'), 10) || 0;
  }

  function isSelected(block) {
    return $(block).find('.checkbox').first().attr('aria-checked') === 'true';
  }

  /**
   * Finds what an action in a field's menu needs: the entry editor that holds the field, and the field's handle. A field in
   * a nested entry's slideout, or in a block of another field, has no draft of its own to export from or paste into.
   */
  function context(field) {
    const $field = $(field);
    const $form = $field.closest('form');
    const editor = $form.data('elementEditor');
    const nested = $field.parent().closest('.slideout-container, .matrixblock, .ni_block, .card').length > 0;

    if (!editor || nested || !$field.attr('data-attribute')) {
      Craft.cp.displayError(t('Copy and paste for another environment work on the blocks of an entry’s own fields.'));
      return null;
    }

    return {$field, editor, handle: $field.attr('data-attribute')};
  }

  /**
   * The modal that previews a pasted Payload and pastes its blocks into a field: first the Payload (pasted or uploaded),
   * then the Preview, with the choices the utility's Preview has. Nothing is added to the draft until the user pastes.
   */
  class PasteModal {
    constructor(editor, handle, payload) {
      this.editor = editor;
      this.handle = handle;
      // The body scrolls and the footer, with the buttons, stays below it, so the buttons are never cut off however
      // tall the Preview is. "fitted" makes the modal as tall as its content (Craft's modals are otherwise 66% of the
      // window), and Garnish caps that at the window less the gutters. The flex column is on an inner element because
      // Garnish sets the modal's own display when it shows it.
      this.$form = $('<form class="modal fitted dolly-paste-modal" method="post" enctype="multipart/form-data"/>');
      const $layout = $('<div/>').css({display: 'flex', flexDirection: 'column', height: '100%'}).appendTo(this.$form);
      this.$body = $('<div class="body"/>').css({flex: '1 1 auto', minHeight: 0, overflow: 'auto'}).appendTo($layout);
      this.$content = $('<div/>').appendTo(this.$body);
      this.$footer = $('<div class="footer"/>').css({flex: 'none'}).appendTo($layout);
      this.modal = new Garnish.Modal(this.$form, {
        autoShow: true,
        minGutter: 40,
        onHide: () => {
          this.resizeObserver?.disconnect();
          this.modal.destroy();
          this.$form.remove();
        },
      });
      // Garnish clears an inline width each time it sizes the modal: 56rem, narrowed to fit the window.
      this.modal.desiredWidth = 56 * parseFloat(getComputedStyle(document.documentElement).fontSize);
      this.modal.updateSizeAndPosition();
      // Garnish sets the modal's height when it's shown: size it again whenever the content changes, as when a picker in
      // the Preview finishes loading or a panel is shown.
      if (window.ResizeObserver) {
        let frame = null;
        this.resizeObserver = new ResizeObserver(() => {
          cancelAnimationFrame(frame);
          frame = requestAnimationFrame(() => this.modal.updateSizeAndPosition());
        });
        this.resizeObserver.observe(this.$content[0]);
      }
      this.$form.on('submit', (ev) => {
        ev.preventDefault();
        this.submit();
      });

      if (payload) {
        this.preview(payload);
      } else {
        this.showInput();
      }
    }

    showInput() {
      this.lastResponse = null;
      this.$content.empty().append(
        $('<h2/>').text(t('Paste from another environment')),
        $('<p class="light"/>').text(
          t('Paste Payload text, or upload a .dolly file. You will see a Preview before anything is added to your draft.')
        ),
        $('<textarea name="payload" rows="8" class="text fullwidth code" autofocus/>'),
        $('<div class="field"/>').append(
          $('<div class="heading"/>').append($('<label/>').text(t('Or upload a .dolly file'))),
          $('<input type="file" name="payloadFile" accept=".dolly,text/plain"/>')
        )
      );
      this.$footer.empty().append(
        $('<div class="buttons"/>').append(
          $('<button type="submit" class="btn submit"/>').text(t('Preview')),
          $('<button type="button" class="btn"/>').text(t('Cancel')).on('click', () => this.modal.hide())
        )
      );
      this.step = 'input';
    }

    /**
     * Asks for the Preview of the Payload in the form (or the given text) with the choices made so far, and shows it.
     */
    async preview(payload = null) {
      this.$form.addClass('loading');
      try {
        // Pasting needs the draft that holds the changes on screen: save them, creating the provisional draft if there's none.
        await this.editor.checkForm(!this.editor.settings.draftId, true);

        const data = this.formData();
        if (payload !== null) {
          data.set('payload', payload);
        }
        const {data: response} = await Craft.sendActionRequest('POST', 'dolly/paste/preview', {data});
        this.show(response);
      } catch (error) {
        Craft.cp.displayError(error?.response?.data?.message || t('Couldn’t preview the Payload.'));
        // Never leave the modal with a disabled button and choices that no longer re-plan: show the last Preview again.
        if (this.lastResponse) {
          this.show(this.lastResponse);
        } else {
          this.showInput();
        }
      } finally {
        this.$form.removeClass('loading');
      }
    }

    show(response) {
      this.lastResponse = response;
      Craft.appendHeadHtml(response.headHtml);
      this.$content.html(response.html);
      Craft.appendBodyHtml(response.bodyHtml);
      this.step = 'preview';

      const $preview = this.$content.find('#dolly-paste-preview');
      this.$content.find('[data-dolly-cancel]').on('click', () => this.modal.hide());
      this.$content.find('[data-dolly-back]').on('click', () => this.showInput());
      this.$content.find('[data-dolly-update]').on('click', () => this.preview());
      if ($preview.find('button.submit').length) {
        // Before the buttons move to the footer: the Preview keeps the Paste button off while a choice is being re-planned.
        Dolly.bindPreview($preview[0], () => this.preview());
      }
      this.$footer.empty().append($preview.children('.buttons'));
      this.modal.updateSizeAndPosition();
    }

    formData() {
      const data = new FormData(this.$form[0]);
      data.set('elementId', this.editor.settings.elementId);
      data.set('siteId', this.editor.settings.siteId);
      data.set('field', this.handle);

      return data;
    }

    async submit() {
      if (this.step !== 'preview') {
        await this.preview();
        return;
      }

      this.$form.addClass('loading');
      this.$form.find('button.submit').prop('disabled', true);
      try {
        await Craft.sendActionRequest('POST', 'dolly/paste/paste', {data: this.formData()});
        this.modal.hide();
        // The blocks were added to the draft on the server, so the page loads the draft again to show them. The
        // confirmation is a flash message, which the page shows once it has loaded.
        window.location.reload();
      } catch (error) {
        Craft.cp.displayError(error?.response?.data?.message || t('Couldn’t paste the blocks.'));
        // The choices may be what's wrong: show the Preview as it is now.
        this.preview();
      } finally {
        this.$form.removeClass('loading');
      }
    }
  }

  /**
   * Copies the blocks at the given positions of a field, after the editor has saved what's on screen.
   */
  function copyBlocks(ctx, blocks, positions) {
    const ids = positions.map((position) => blockId(blocks[position]));

    return Dolly.exportAndDeliver('dolly/export/blocks', async () => {
      await ctx.editor.checkForm(false, true);

      return {
        elementId: ctx.editor.settings.elementId,
        siteId: ctx.editor.settings.siteId,
        field: ctx.handle,
        positions,
        ids,
      };
    });
  }

  /**
   * Adds "Copy for another environment" to the action menu of a block in a Matrix field (cards or inline) or a Neo field,
   * before the menu opens, for a user who can Export content. Craft builds these menus itself, so the item is added
   * the first time one is used.
   */
  document.addEventListener(
    'click',
    (ev) => {
      if (!window.dollyCanExport) {
        return;
      }
      const button = ev.target.closest?.('.nested-element-cards .card .action-btn, .matrixblock .action-btn, .ni_block .action-btn');
      const menu = button && $(button).data('disclosureMenu');
      if (!menu || menu.dollyItem) {
        return;
      }

      menu.dollyItem = true;
      const block = $(button).closest('.card, .matrixblock, .ni_block')[0];
      const $field = $(block).parent().closest('.field');
      const group = menu.addGroup(null, true, menu.getFirstDestructiveGroup());
      menu.addItem(
        {
          icon: async () => await Craft.ui.icon('clone'),
          label: t('Copy for another environment'),
          onActivate: () => Dolly.copyBlockFrom($field, block),
        },
        group
      );
    },
    true
  );

  Object.assign((window.Dolly = window.Dolly || {}), {
    /**
     * Copies the blocks selected in a Matrix or Neo field, with the blocks nested under them, for another environment.
     * The blocks are found in the draft by their position in the field, after the editor has saved what's on screen.
     */
    copySelectedBlocks(field) {
      const ctx = context(field);
      if (!ctx) {
        return;
      }

      const blocks = blocksOf(ctx.$field).toArray();
      const positions = blocks.flatMap((block, position) => (isSelected(block) ? [position] : []));
      if (!positions.length) {
        Craft.cp.displayError(t('Select one or more blocks to copy first.'));
        return;
      }

      return copyBlocks(ctx, blocks, positions);
    },

    /**
     * Copies the block whose action menu was used, or all the selected blocks if it's one of them, as Craft's own Copy does.
     */
    copyBlockFrom(field, block) {
      const ctx = context(field);
      if (!ctx) {
        return;
      }

      const blocks = blocksOf(ctx.$field).toArray();
      const selected = blocks.flatMap((el, position) => (isSelected(el) ? [position] : []));
      const own = blocks.indexOf(block);

      return copyBlocks(ctx, blocks, isSelected(block) ? selected : [own]);
    },

    /**
     * Opens the modal that previews a Payload and pastes its blocks into a Matrix or Neo field, in the user's draft.
     * A Payload on the clipboard is previewed straight away.
     */
    async pasteBlocks(field) {
      const ctx = context(field);
      if (!ctx) {
        return;
      }

      let text = '';
      try {
        text = (await navigator.clipboard.readText()).trim();
      } catch (e) {
        // The browser won't let the page read the clipboard: the modal asks for the Payload instead.
      }

      new PasteModal(ctx.editor, ctx.handle, text.startsWith('dolly:') ? text : null);
    },
  });
})();
