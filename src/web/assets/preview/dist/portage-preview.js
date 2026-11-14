/* global Craft, $ */
(function () {
  window.Portage = window.Portage || {};

  /**
   * Keeps a Preview's choices in step with the Preview: a change to a choice asks for the Preview to be rebuilt, and the
   * Import button stays off from the moment a choice changes until the Preview has been rebuilt, so the Import can't run
   * choices the Preview hasn't shown. Shared by the utility's Preview and the Preview in the paste modal.
   *
   * @param {HTMLElement} root the Preview: the element the choices are in, which the caller replaces with the rebuilt Preview
   * @param {function(): void} replan rebuilds the Preview from the choices in the form
   */
  Portage.bindPreview = function (root, replan) {
    const importButton = root.querySelector('button.submit');
    let replanning = false;
    let timer = null;

    function replanOnce() {
      if (replanning) {
        return;
      }
      replanning = true;
      clearTimeout(timer);
      replan();
    }

    function pending() {
      if (importButton) {
        importButton.disabled = true;
      }
    }

    root.addEventListener('input', function (event) {
      if (event.target.matches('input[type="text"][data-portage-replan]')) {
        pending();
        clearTimeout(timer);
        timer = setTimeout(replanOnce, 600);
      }
    });

    root.addEventListener('change', function (event) {
      const target = event.target;
      if (!target.matches('[data-portage-replan]')) {
        return;
      }
      pending();
      if (target.matches('[data-portage-choice]')) {
        const row = target.closest('[data-portage-reference]');
        row.querySelectorAll('[data-panel]').forEach(function (panel) {
          panel.classList.toggle('hidden', panel.getAttribute('data-panel').split(' ').indexOf(target.value) === -1);
        });
      }
      if (target.matches('[data-portage-section]')) {
        // The matching entry depends on the section, so go back to creating a new entry.
        const mode = target.closest('.pane').querySelector('[data-portage-mode]');
        if (mode) {
          mode.value = 'create';
        }
      }
      replanOnce();
    });

    // Craft's element selector adds and removes hidden inputs rather than firing a change event.
    root.querySelectorAll('[data-portage-reference] .elementselect').forEach(function (picker) {
      const count = function () {
        return picker.querySelectorAll('input[type="hidden"][name$="[replacement][]"]').length;
      };
      let before = count();
      new MutationObserver(function () {
        if (count() !== before) {
          before = count();
          pending();
          replanOnce();
        }
      }).observe(picker, {childList: true, subtree: true});
    });
  };
})();
