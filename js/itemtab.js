(() => {
  'use strict';

  const instances = new WeakMap();
  let activeInstance = null;

  function twoDigits(value) {
    return String(value).padStart(2, '0');
  }

  function localDateValue(date) {
    return `${date.getFullYear()}-${twoDigits(date.getMonth() + 1)}-${twoDigits(date.getDate())}`;
  }

  function timeValue(date) {
    return `${twoDigits(date.getHours())}:${twoDigits(date.getMinutes())}`;
  }

  function defaultInterval() {
    const start = new Date();
    start.setMinutes(0, 0, 0);
    if (start.getHours() >= 23) start.setHours(22);
    const end = new Date(start.getTime() + 60 * 60 * 1000);
    return { start, end };
  }

  function parseJsonResponse(response, text) {
    try {
      return JSON.parse(text);
    } catch (_error) {
      throw new Error(`O servidor retornou uma resposta inválida (HTTP ${response.status}).`);
    }
  }

  function createInstance(root) {
    const createModal = root.querySelector('#ap-create-modal');
    const createDialog = createModal?.querySelector('.ap-create-dialog');
    const createForm = createModal?.querySelector('#ap-create-form');
    const modalError = createModal?.querySelector('.ap-modal-error');
    const modalErrorText = modalError?.querySelector('span');
    const modalContent = createModal?.querySelector('[name="content"]');
    const modalDate = createModal?.querySelector('[name="appointment_date"]');
    const modalBegin = createModal?.querySelector('[name="begin_time_hour"]');
    const modalEnd = createModal?.querySelector('[name="end_time_hour"]');
    const modalToken = createModal?.querySelector('[name="_glpi_csrf_token"]');
    const detailsToggle = createModal?.querySelector('.ap-details-toggle');
    const detailsPanel = createModal?.querySelector('#ap-create-details');
    const modalSave = createModal?.querySelector('.ap-modal-save');
    const discardConfirm = createModal?.querySelector('.ap-discard-confirm');
    const discardDialog = discardConfirm?.querySelector('.ap-discard-dialog');
    const discardContinue = discardConfirm?.querySelector('[data-modal-action="continue-editing"]');
    const panel = root.querySelector('.ap-itemtab-panel');
    const successBox = root.querySelector('.ap-itemtab-success');
    const errorBox = root.querySelector('.ap-itemtab-error');
    const successText = successBox?.querySelector('span');
    const errorText = errorBox?.querySelector('span');
    let csrfToken = root.dataset.createCsrfToken || '';
    let dirty = false;
    let submitting = false;
    let opener = null;
    let discardOpener = null;
    let settingValues = false;

    function showMessage(box, textElement, message) {
      if (!box || !textElement) return;
      textElement.textContent = String(message || '');
      box.hidden = false;
    }

    function hideMessage(box) {
      if (box) box.hidden = true;
    }

    function setDetailsExpanded(expanded) {
      if (!detailsToggle || !detailsPanel) return;
      const isExpanded = Boolean(expanded);
      detailsToggle.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
      detailsPanel.hidden = !isExpanded;
      const label = detailsToggle.querySelector('span');
      if (label) label.textContent = isExpanded ? 'Ocultar detalhes' : 'Exibir detalhes';
      const icon = detailsToggle.querySelector('i');
      if (icon) icon.className = isExpanded ? 'ti ti-chevron-up' : 'ti ti-chevron-down';
    }

    function hideModalError() {
      if (modalError) modalError.hidden = true;
    }

    function showModalError(message, showDetails = false) {
      if (!modalError || !modalErrorText) return;
      modalErrorText.textContent = String(message || 'Não foi possível salvar o apontamento.');
      modalError.hidden = false;
      if (showDetails) setDetailsExpanded(true);
      modalError.focus?.();
    }

    function open(trigger) {
      if (!createModal || !createForm || root.dataset.canCreate !== '1') return;
      const interval = defaultInterval();
      settingValues = true;
      createForm.reset();
      hideModalError();
      hideMessage(successBox);
      hideMessage(errorBox);
      setDetailsExpanded(false);
      if (modalDate) modalDate.value = localDateValue(interval.start);
      if (modalBegin) modalBegin.value = timeValue(interval.start);
      if (modalEnd) modalEnd.value = timeValue(interval.end);
      if (modalToken) modalToken.value = csrfToken;
      dirty = false;
      opener = trigger instanceof HTMLElement ? trigger : document.activeElement;
      createModal.hidden = false;
      document.body.classList.add('ap-modal-open');
      settingValues = false;
      activeInstance = api;
      window.setTimeout(() => modalContent?.focus(), 0);
    }

    function openDiscardConfirmation() {
      if (!discardConfirm || !createDialog || !dirty) return false;
      discardOpener = document.activeElement;
      discardConfirm.hidden = false;
      discardContinue?.focus();
      createDialog.inert = true;
      createDialog.setAttribute('aria-hidden', 'true');
      return true;
    }

    function closeDiscardConfirmation(returnFocus = true) {
      if (!discardConfirm || discardConfirm.hidden) return;
      discardConfirm.hidden = true;
      if (createDialog) {
        createDialog.inert = false;
        createDialog.removeAttribute('aria-hidden');
      }
      const previousFocus = discardOpener;
      discardOpener = null;
      if (returnFocus && previousFocus instanceof HTMLElement) {
        window.setTimeout(() => previousFocus.focus(), 0);
      }
    }

    function close(force = false) {
      if (!createModal || createModal.hidden) return true;
      if (!force && dirty) {
        openDiscardConfirmation();
        return false;
      }
      closeDiscardConfirmation(false);
      createModal.hidden = true;
      document.body.classList.remove('ap-modal-open');
      hideModalError();
      dirty = false;
      const returnFocus = opener;
      opener = null;
      if (activeInstance === api) activeInstance = null;
      if (returnFocus instanceof HTMLElement) window.setTimeout(() => returnFocus.focus(), 0);
      return true;
    }

    function focusableElements() {
      const activeDialog = discardConfirm && !discardConfirm.hidden ? discardDialog : createDialog;
      if (!activeDialog) return [];
      return Array.from(activeDialog.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'))
        .filter(element => !element.hidden && element.getClientRects().length > 0);
    }

    function setSubmitting(value) {
      submitting = value;
      if (!modalSave) return;
      modalSave.disabled = value;
      modalSave.setAttribute('aria-busy', value ? 'true' : 'false');
      const spinner = modalSave.querySelector('.ap-save-spinner');
      const icon = modalSave.querySelector('.ap-save-icon');
      if (spinner) spinner.hidden = !value;
      if (icon) icon.hidden = value;
    }

    async function refreshPanel() {
      if (!panel || !root.dataset.refreshEndpoint) return;
      const url = new URL(root.dataset.refreshEndpoint, window.location.origin);
      url.searchParams.set('context_itemtype', root.dataset.contextItemtype || '');
      url.searchParams.set('context_items_id', root.dataset.contextItemsId || '');
      const response = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      const result = parseJsonResponse(response, await response.text());
      if (!response.ok || !result.success || typeof result.html !== 'string') {
        throw new Error(result.message || `Não foi possível atualizar os apontamentos (HTTP ${response.status}).`);
      }
      panel.innerHTML = result.html;
    }

    async function submit(event) {
      event.preventDefault();
      if (!createForm || submitting) return;
      hideModalError();
      if (!createForm.reportValidity()) return;
      if (modalToken) modalToken.value = csrfToken;
      setSubmitting(true);
      try {
        const response = await fetch(root.dataset.createEndpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-Glpi-Csrf-Token': csrfToken,
          },
          body: new FormData(createForm),
        });
        const result = parseJsonResponse(response, await response.text());
        if (result.csrf_token) {
          csrfToken = result.csrf_token;
          root.dataset.createCsrfToken = result.csrf_token;
          if (modalToken) modalToken.value = result.csrf_token;
        }
        if (!response.ok || !result.success) {
          showModalError(result.message || `Não foi possível salvar o apontamento (HTTP ${response.status}).`, Boolean(result.show_details));
          return;
        }
        close(true);
        try {
          await refreshPanel();
          showMessage(successBox, successText, result.message || 'Apontamento salvo com sucesso.');
        } catch (refreshError) {
          showMessage(successBox, successText, result.message || 'Apontamento salvo com sucesso.');
          showMessage(errorBox, errorText, `${refreshError.message} Atualize a aba para visualizar o novo registro.`);
        }
      } catch (error) {
        showModalError(error.message || 'Não foi possível salvar o apontamento.');
      } finally {
        setSubmitting(false);
      }
    }

    createForm?.addEventListener('submit', submit);
    createForm?.addEventListener('input', () => {
      if (!settingValues) dirty = true;
    });
    createForm?.addEventListener('change', () => {
      if (!settingValues) dirty = true;
    });
    detailsToggle?.addEventListener('click', () => {
      setDetailsExpanded(detailsToggle.getAttribute('aria-expanded') !== 'true');
    });
    createModal?.addEventListener('click', event => {
      const target = event.target instanceof Element ? event.target : null;
      const action = target?.closest('[data-modal-action]')?.dataset.modalAction;
      if (action === 'cancel') close(false);
      if (action === 'continue-editing') closeDiscardConfirmation(true);
      if (action === 'discard') close(true);
    });

    const api = {
      root,
      open,
      close,
      closeDiscardConfirmation,
      discardIsOpen: () => Boolean(discardConfirm && !discardConfirm.hidden),
      focusableElements,
      activeDialog: () => (discardConfirm && !discardConfirm.hidden ? discardDialog : createDialog),
    };
    return api;
  }

  function instanceFor(root) {
    if (!instances.has(root)) instances.set(root, createInstance(root));
    return instances.get(root);
  }

  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    const trigger = target?.closest('.ap-itemtab [data-ap-action="open-create"]');
    if (!trigger) return;
    const root = trigger.closest('.ap-itemtab');
    if (!root) return;
    event.preventDefault();
    instanceFor(root)?.open(trigger);
  });

  document.addEventListener('keydown', event => {
    if (!activeInstance || !activeInstance.root.isConnected) {
      activeInstance = null;
      return;
    }
    if (event.key === 'Escape') {
      event.preventDefault();
      if (activeInstance.discardIsOpen()) activeInstance.closeDiscardConfirmation(true);
      else activeInstance.close(false);
      return;
    }
    if (event.key !== 'Tab') return;
    const focusable = activeInstance.focusableElements();
    if (!focusable.length) {
      event.preventDefault();
      activeInstance.activeDialog()?.focus();
      return;
    }
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });
})();
