/* global Craft, $ */
(function () {
  const t = (message, params) => Craft.t('portage', message, params);

  /**
   * Copies text without the Clipboard API's promise support, for browsers that lack it.
   */
  async function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      try {
        await navigator.clipboard.writeText(text);
        return true;
      } catch (e) {
        // fall through to the textarea fallback
      }
    }

    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    try {
      return document.execCommand('copy');
    } catch (e) {
      return false;
    } finally {
      textarea.remove();
    }
  }

  function download(filename, text) {
    const url = URL.createObjectURL(new Blob([text], {type: 'text/plain'}));
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.hidden = true;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  /**
   * Offers the Payload as a `.portage` file: a notice with a button, so the download happens in the user's own click.
   */
  function offerDownload(message, response) {
    const button = $('<button type="button" class="btn small"/>').text(
      t('Download {filename}', {filename: response.filename})
    );
    button.on('click', () => {
      download(response.filename, response.payload);
      notification.close();
    });
    const notification = Craft.cp.displayNotice(message, {details: button});
  }

  /**
   * Exports through the server, then puts the Payload on the clipboard or offers it as a file.
   *
   * The clipboard write starts in the click that called this, with a promise for the Payload, because browsers only
   * allow it during a user gesture, and the request takes longer than the gesture lasts.
   *
   * @param {string} action the export controller action
   * @param {function(): Promise<Object>} getData resolves to the request's data, once the page is ready to ask
   */
  async function exportAndDeliver(action, getData) {
    const outcome = (async () => {
      try {
        const data = await getData();
        return {response: (await Craft.sendActionRequest('POST', action, {data})).data};
      } catch (error) {
        return {error};
      }
    })();

    // Large Payloads and failures aren't copied: the promise rejects, which cancels the clipboard write.
    const blob = outcome.then(({response, error}) => {
      if (error || response.asFile) {
        throw new Error('Not copied');
      }
      return new Blob([response.payload], {type: 'text/plain'});
    });
    blob.catch(() => {});

    let copied = false;
    if (window.ClipboardItem && navigator.clipboard?.write) {
      try {
        await navigator.clipboard.write([new ClipboardItem({'text/plain': blob})]);
        copied = true;
      } catch (e) {
        // reported below, from the outcome
      }
    }

    const {response, error} = await outcome;
    if (error) {
      Craft.cp.displayError(error?.response?.data?.message || t('Couldn’t copy for another environment.'));
      return;
    }

    if (response.asFile) {
      offerDownload(t('The Payload is large. Download it as {filename}, then upload it in the Portage utility.', {filename: response.filename}), response);
      return;
    }

    copied = copied || (!window.ClipboardItem && (await copyText(response.payload)));
    if (copied) {
      Craft.cp.displayNotice(
        response.count === 1
          ? t('Copied for another environment: 1 entry.')
          : t('Copied for another environment: {count} entries.', {count: response.count})
      );
    } else {
      offerDownload(t('The Payload couldn’t be copied. Download it as {filename}, then upload it in the Portage utility.', {filename: response.filename}), response);
    }
  }

  window.Portage = {
    /**
     * Exports the entry an element editor is showing, including changes that haven't been saved to its draft yet.
     */
    exportEditedEntry(editor) {
      return exportAndDeliver('portage/export/entry', async () => {
        // Saves unsaved changes to the draft (creating the provisional draft if the entry has none) and waits.
        await editor.checkForm(false, true);

        return {elementId: editor.settings.elementId, siteId: editor.settings.siteId};
      });
    },

    /**
     * Exports the entries selected in an element index, as the canonical entries.
     */
    exportEntries(ids, siteId) {
      return exportAndDeliver('portage/export/entries', async () => ({ids, siteId}));
    },
  };
})();
