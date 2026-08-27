(() => {
  'use strict';

  const root = document.getElementById('apontamentos-calendar');
  if (!root) return;

  const body = root.querySelector('.ap-calendar-body');
  const title = root.querySelector('.ap-title');
  const loading = root.querySelector('.ap-loading');
  const errorBox = root.querySelector('.ap-error');
  const successBox = root.querySelector('.ap-success');
  const errorText = errorBox?.querySelector('.ap-message-text');
  const successText = successBox?.querySelector('.ap-message-text');
  const periodTotal = root.querySelector('.ap-period-total strong');
  const userFilter = root.querySelector('[name="ap_user_filter"]');
  const endpoint = root.dataset.endpoint;
  const deleteEndpoint = root.dataset.deleteEndpoint;
  const createEndpoint = root.dataset.createEndpoint;
  let csrfToken = root.dataset.csrfToken;
  let createCsrfToken = root.dataset.createCsrfToken;
  const canCreate = root.dataset.canCreate === '1';
  const canManageOthers = root.dataset.canManageOthers === '1';
  const locale = 'pt-BR';
  const hourStart = 6;
  const hourEnd = 22;
  const slotHeight = 64;

  let view = ['month', 'week', 'day'].includes(root.dataset.initialView)
    ? root.dataset.initialView
    : 'week';
  let cursor = /^\d{4}-\d{2}-\d{2}$/.test(root.dataset.focusDate || '')
    ? startOfDay(new Date(`${root.dataset.focusDate}T12:00:00`))
    : startOfDay(new Date());
  let events = [];
  let schedule = {};
  let requestController = null;
  let selectedUserValue = userFilter?.value || '';
  let userReloadQueued = false;
  const createModal = root.querySelector('#ap-create-modal');
  const createDialog = createModal?.querySelector('.ap-create-dialog');
  const createForm = createModal?.querySelector('#ap-create-form');
  const modalError = createModal?.querySelector('.ap-modal-error');
  const modalErrorText = modalError?.querySelector('span');
  const modalContent = createModal?.querySelector('[name="content"]');
  const modalDate = createModal?.querySelector('[name="appointment_date"]');
  const modalBegin = createModal?.querySelector('[name="begin_time_hour"]');
  const modalEnd = createModal?.querySelector('[name="end_time_hour"]');
  const modalUser = createModal?.querySelector('[name="users_id"]');
  const modalToken = createModal?.querySelector('[name="_glpi_csrf_token"]');
  const modalLinkType = createModal?.querySelector('[name="link_type"]');
  const modalProject = createModal?.querySelector('#ap-modal-project');
  const modalTask = createModal?.querySelector('#ap-modal-task');
  const modalProjectFields = createModal?.querySelector('.ap-modal-project-fields');
  const detailsToggle = createModal?.querySelector('.ap-details-toggle');
  const detailsPanel = createModal?.querySelector('#ap-create-details');
  const modalSave = createModal?.querySelector('.ap-modal-save');
  const discardConfirm = createModal?.querySelector('.ap-discard-confirm');
  const discardDialog = discardConfirm?.querySelector('.ap-discard-dialog');
  const discardContinue = discardConfirm?.querySelector('[data-modal-action="continue-editing"]');
  let modalDirty = false;
  let modalSubmitting = false;
  let modalOpener = null;
  let discardOpener = null;
  let settingModalValues = false;

  function showMessage(box, textElement, message) {
    if (!box || !textElement) return;
    textElement.textContent = String(message || '');
    box.hidden = false;
  }

  function hideMessage(box) {
    if (box) box.hidden = true;
  }

  function twoDigits(value) {
    return String(value).padStart(2, '0');
  }

  function timeValue(date) {
    return `${twoDigits(date.getHours())}:${twoDigits(date.getMinutes())}`;
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

  function syncLinkedPicker() {
    if (!createModal || !modalLinkType) return;
    const selectedType = modalLinkType.value;
    const isProject = selectedType === 'Project';
    createModal.querySelectorAll('.ap-modal-linked-picker').forEach(select => {
      const active = !isProject && select.dataset.linkType === selectedType;
      select.hidden = !active;
      select.disabled = !active;
    });
    const linkedField = createModal.querySelector('.ap-modal-linked-field');
    if (linkedField) linkedField.hidden = isProject;
    const noLink = createModal.querySelector('.ap-modal-no-link');
    if (noLink) noLink.hidden = selectedType !== '';
    if (modalProjectFields) modalProjectFields.hidden = !isProject;
    if (modalProject) {
      modalProject.disabled = !isProject;
      if (!isProject) modalProject.value = '0';
    }
    if (!isProject && modalTask) {
      modalTask.value = '0';
      modalTask.disabled = true;
    }
    if (isProject) syncProjectTasks();
  }

  function syncProjectTasks() {
    if (!modalProject || !modalTask) return;
    const projectId = modalProject.value;
    let selectionStillValid = modalTask.value === '0' || modalTask.value === '';
    Array.from(modalTask.options).forEach(option => {
      if (!option.value || option.value === '0') {
        option.hidden = false;
        return;
      }
      const visible = projectId !== '' && option.dataset.projectId === projectId;
      option.hidden = !visible;
      if (visible && option.selected) selectionStillValid = true;
    });
    if (!selectionStillValid) modalTask.value = '0';
    modalTask.disabled = modalProject.disabled || projectId === '' || projectId === '0';
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

  function currentModalUser() {
    if (canManageOthers && userFilter?.value) return userFilter.value;
    return root.dataset.currentUser || '';
  }

  function openCreateModal(start, end, opener = null) {
    if (!canCreate || !createModal || !createForm) return;
    settingModalValues = true;
    createForm.reset();
    hideModalError();
    setDetailsExpanded(false);
    if (modalDate) modalDate.value = isoDate(start);
    if (modalBegin) modalBegin.value = timeValue(start);
    if (modalEnd) modalEnd.value = timeValue(end);
    if (modalUser) modalUser.value = currentModalUser();
    if (modalToken) modalToken.value = createCsrfToken;
    syncLinkedPicker();
    syncProjectTasks();
    modalDirty = false;
    modalOpener = opener instanceof HTMLElement ? opener : document.activeElement;
    createModal.hidden = false;
    document.body.classList.add('ap-modal-open');
    settingModalValues = false;
    window.setTimeout(() => modalContent?.focus(), 0);
  }

  function openDiscardConfirmation() {
    if (!discardConfirm || !createDialog || !modalDirty) return false;
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

  function closeCreateModal(force = false) {
    if (!createModal || createModal.hidden) return true;
    if (!force && modalDirty) {
      openDiscardConfirmation();
      return false;
    }
    closeDiscardConfirmation(false);
    createModal.hidden = true;
    document.body.classList.remove('ap-modal-open');
    hideModalError();
    modalDirty = false;
    const returnFocus = modalOpener;
    modalOpener = null;
    if (returnFocus instanceof HTMLElement) window.setTimeout(() => returnFocus.focus(), 0);
    return true;
  }

  function focusableModalElements() {
    const activeDialog = discardConfirm && !discardConfirm.hidden ? discardDialog : createDialog;
    if (!activeDialog) return [];
    return Array.from(activeDialog.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'))
      .filter(element => !element.hidden && element.getClientRects().length > 0);
  }

  function setModalSubmitting(submitting) {
    modalSubmitting = submitting;
    if (!modalSave) return;
    modalSave.disabled = submitting;
    modalSave.setAttribute('aria-busy', submitting ? 'true' : 'false');
    const spinner = modalSave.querySelector('.ap-save-spinner');
    const icon = modalSave.querySelector('.ap-save-icon');
    if (spinner) spinner.hidden = !submitting;
    if (icon) icon.hidden = submitting;
  }

  async function submitCreateModal(event) {
    event.preventDefault();
    if (!createForm || modalSubmitting) return;
    hideModalError();
    if (!createForm.reportValidity()) return;
    if (modalUser) modalUser.value = currentModalUser();
    if (modalToken) modalToken.value = createCsrfToken;
    setModalSubmitting(true);
    try {
      const response = await fetch(createEndpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-Glpi-Csrf-Token': createCsrfToken,
        },
        body: new FormData(createForm),
      });
      const responseText = await response.text();
      let result;
      try {
        result = JSON.parse(responseText);
      } catch (_error) {
        throw new Error(`O servidor retornou uma resposta inválida (HTTP ${response.status}).`);
      }
      if (result.csrf_token) {
        createCsrfToken = result.csrf_token;
        root.dataset.createCsrfToken = result.csrf_token;
        if (modalToken) modalToken.value = result.csrf_token;
      }
      if (!response.ok || !result.success) {
        showModalError(result.message || `Não foi possível salvar o apontamento (HTTP ${response.status}).`, Boolean(result.show_details));
        return;
      }
      closeCreateModal(true);
      await loadEvents();
      showMessage(successBox, successText, result.message || 'Apontamento salvo com sucesso.');
    } catch (error) {
      showModalError(error.message || 'Não foi possível salvar o apontamento.');
    } finally {
      setModalSubmitting(false);
    }
  }

  function updateCalendarUrl() {
    const url = new URL(window.location.href);
    if (canManageOthers && userFilter?.value) url.searchParams.set('users_id', userFilter.value);
    else url.searchParams.delete('users_id');
    url.searchParams.set('date', isoDate(cursor));
    url.searchParams.set('view', view);
    window.history.replaceState({}, '', url);
  }

  function userSelectionChanged() {
    const value = userFilter?.value || '';
    if (value === selectedUserValue) return;
    selectedUserValue = value;
    if (userReloadQueued) return;
    userReloadQueued = true;
    queueMicrotask(() => {
      userReloadQueued = false;
      updateCalendarUrl();
      loadEvents();
    });
  }

  function startOfDay(date) {
    const value = new Date(date);
    value.setHours(0, 0, 0, 0);
    return value;
  }

  function addDays(date, days) {
    const value = new Date(date);
    value.setDate(value.getDate() + days);
    return value;
  }

  function startOfWeek(date) {
    const value = startOfDay(date);
    value.setDate(value.getDate() - value.getDay());
    return value;
  }

  function startOfMonth(date) {
    return new Date(date.getFullYear(), date.getMonth(), 1);
  }

  function isoDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }

  function range() {
    if (view === 'day') return { start: startOfDay(cursor), end: addDays(startOfDay(cursor), 1) };
    if (view === 'month') {
      const start = startOfWeek(startOfMonth(cursor));
      return { start, end: addDays(start, 42) };
    }
    const start = startOfWeek(cursor);
    return { start, end: addDays(start, 7) };
  }

  function formatDuration(minutes) {
    const safe = Math.max(0, Math.round(minutes));
    return `${Math.floor(safe / 60)}h ${String(safe % 60).padStart(2, '0')}m`;
  }

  function eventMinutes(event) {
    return Math.max(0, (new Date(event.end.replace(' ', 'T')) - new Date(event.start.replace(' ', 'T'))) / 60000);
  }

  function countsForTotal(event) {
    return true;
  }

  function escapeText(value) {
    const span = document.createElement('span');
    span.textContent = String(value ?? '');
    return span.innerHTML;
  }

  function updateTitle(currentRange) {
    if (view === 'day') {
      title.textContent = currentRange.start.toLocaleDateString(locale, { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' });
    } else if (view === 'month') {
      title.textContent = cursor.toLocaleDateString(locale, { month: 'long', year: 'numeric' });
    } else {
      const last = addDays(currentRange.end, -1);
      title.textContent = `${currentRange.start.toLocaleDateString(locale, { day: '2-digit', month: 'short' })} – ${last.toLocaleDateString(locale, { day: '2-digit', month: 'short', year: 'numeric' })}`;
    }
  }

  async function loadEvents() {
    const currentRange = range();
    updateTitle(currentRange);
    loading.hidden = false;
    hideMessage(errorBox);
    body.setAttribute('aria-busy', 'true');
    if (requestController) requestController.abort();
    requestController = new AbortController();
    const params = new URLSearchParams({ start: isoDate(currentRange.start), end: isoDate(currentRange.end) });
    if (canManageOthers && userFilter?.value) params.set('users_id', userFilter.value);
    try {
      const response = await fetch(`${endpoint}?${params}`, { headers: { Accept: 'application/json' }, signal: requestController.signal, credentials: 'same-origin' });
      const data = await response.json();
      if (!response.ok || data.error) throw new Error(data.error || `HTTP ${response.status}`);
      events = Array.isArray(data.events) ? data.events : [];
      schedule = data.schedule && typeof data.schedule === 'object' ? data.schedule : {};
      render();
    } catch (error) {
      if (error.name !== 'AbortError') {
        showMessage(errorBox, errorText, error.message || 'Não foi possível carregar os apontamentos.');
      }
    } finally {
      loading.hidden = true;
      body.removeAttribute('aria-busy');
    }
  }

  function render() {
    const currentRange = range();
    root.querySelectorAll('[data-view]').forEach(button => button.classList.toggle('active', button.dataset.view === view));
    body.innerHTML = '';
    if (view === 'month') renderMonth(currentRange);
    else renderTimeGrid(currentRange);
    const total = events.filter(countsForTotal).reduce((sum, event) => sum + eventMinutes(event), 0);
    periodTotal.textContent = formatDuration(total);
  }

  function applyScheduleColor(element, date) {
    const info = schedule[isoDate(date)];
    if (!info) return;
    if (['met', 'exceeded'].includes(info.state)) {
      element.classList.add('ap-target-met');
      element.title = 'Meta de horas atingida';
    } else if (info.state === 'short') {
      element.classList.add('ap-target-short');
      element.title = 'Meta de horas ainda não atingida';
    }
  }

  function eventsForDay(date) {
    const key = isoDate(date);
    return events.filter(event => event.start.slice(0, 10) === key);
  }

  function renderMonth(currentRange) {
    const wrapper = document.createElement('div');
    wrapper.className = 'ap-month';
    ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'].forEach(label => {
      const header = document.createElement('div');
      header.className = 'ap-month-weekday';
      header.textContent = label;
      wrapper.appendChild(header);
    });
    for (let index = 0; index < 42; index++) {
      const date = addDays(currentRange.start, index);
      const cell = document.createElement('div');
      cell.className = 'ap-month-day';
      if (date.getMonth() !== cursor.getMonth()) cell.classList.add('is-outside');
      if (isoDate(date) === isoDate(new Date())) cell.classList.add('is-today');
      cell.tabIndex = canCreate ? 0 : -1;
      cell.dataset.date = isoDate(date);
      cell.innerHTML = `<div class="ap-month-date">${date.getDate()}</div>`;
      const dayEvents = eventsForDay(date);
      dayEvents.slice(0, 4).forEach(event => cell.appendChild(eventElement(event, true)));
      if (dayEvents.length > 4) {
        const more = document.createElement('div');
        more.className = 'ap-more';
        more.textContent = `+${dayEvents.length - 4} apontamento(s)`;
        cell.appendChild(more);
      }
      const minutes = dayEvents.filter(countsForTotal).reduce((sum, event) => sum + eventMinutes(event), 0);
      const total = document.createElement('div');
      total.className = 'ap-day-total';
      total.textContent = formatDuration(minutes);
      cell.appendChild(total);
      applyScheduleColor(total, date);
      cell.addEventListener('dblclick', event => {
        if (event.target.closest('.ap-event')) return;
        openCreate(date, 9, 10);
      });
      cell.addEventListener('keydown', event => {
        if (!canCreate || !['Enter', ' '].includes(event.key)) return;
        event.preventDefault();
        openCreate(date, 9, 10);
      });
      wrapper.appendChild(cell);
    }
    body.appendChild(wrapper);
  }

  function renderTimeGrid(currentRange) {
    const days = view === 'day' ? [currentRange.start] : Array.from({ length: 7 }, (_, index) => addDays(currentRange.start, index));
    const wrapper = document.createElement('div');
    wrapper.className = `ap-time-view ap-time-view-${view}`;
    wrapper.style.setProperty('--ap-days', days.length);
    wrapper.style.setProperty('--ap-time-columns', `50px repeat(${days.length}, minmax(110px, 1fr))`);
    const header = document.createElement('div');
    header.className = 'ap-time-header';
    header.innerHTML = '<div class="ap-corner">TOTAL</div>';
    days.forEach(date => {
      const dayEvents = eventsForDay(date);
      const minutes = dayEvents.filter(countsForTotal).reduce((sum, event) => sum + eventMinutes(event), 0);
      const cell = document.createElement('div');
      cell.className = 'ap-day-heading';
      if (isoDate(date) === isoDate(new Date())) cell.classList.add('is-today');
      cell.innerHTML = `<span>${date.toLocaleDateString(locale, { weekday: 'short' })}</span><strong>${date.toLocaleDateString(locale, { day: '2-digit', month: '2-digit' })}</strong><small>${formatDuration(minutes)}</small>`;
      applyScheduleColor(cell.querySelector('small'), date);
      header.appendChild(cell);
    });

    const scroll = document.createElement('div');
    scroll.className = 'ap-time-scroll';
    scroll.appendChild(header);
    const grid = document.createElement('div');
    grid.className = 'ap-time-grid';
    grid.style.setProperty('--ap-hours', hourEnd - hourStart);
    grid.style.setProperty('--ap-slot-height', `${slotHeight}px`);
    const labels = document.createElement('div');
    labels.className = 'ap-hour-labels';
    for (let hour = hourStart; hour <= hourEnd; hour++) {
      const label = document.createElement('div');
      label.textContent = String(hour).padStart(2, '0');
      label.style.top = `${(hour - hourStart) * slotHeight}px`;
      labels.appendChild(label);
    }
    grid.appendChild(labels);
    days.forEach((date, dayIndex) => {
      const column = document.createElement('div');
      column.className = 'ap-day-column';
      if (isoDate(date) === isoDate(new Date())) column.classList.add('is-today');
      column.style.gridColumn = String(dayIndex + 2);
      column.dataset.date = isoDate(date);
      column.tabIndex = canCreate ? 0 : -1;
      column.addEventListener('dblclick', event => {
        if (event.target.closest('.ap-event')) return;
        const rect = column.getBoundingClientRect();
        const minutes = Math.max(0, Math.min((hourEnd - hourStart) * 60 - 60, ((event.clientY - rect.top) / slotHeight) * 60));
        const rounded = Math.floor(minutes / 30) * 30;
        openCreate(date, hourStart + Math.floor(rounded / 60), hourStart + Math.floor((rounded + 60) / 60), rounded % 60, (rounded + 60) % 60);
      });
      column.addEventListener('keydown', event => {
        if (!canCreate || !['Enter', ' '].includes(event.key)) return;
        event.preventDefault();
        openCreate(date, 9, 10);
      });
      eventsForDay(date).forEach(event => column.appendChild(positionedEvent(event)));
      grid.appendChild(column);
    });
    scroll.appendChild(grid);
    wrapper.appendChild(scroll);
    body.appendChild(wrapper);
  }

  function positionedEvent(event) {
    const element = eventElement(event, false);
    const start = new Date(event.start.replace(' ', 'T'));
    const end = new Date(event.end.replace(' ', 'T'));
    const startMinutes = (start.getHours() - hourStart) * 60 + start.getMinutes();
    const duration = Math.max(20, (end - start) / 60000);
    if (duration < 60) element.classList.add('is-short');
    element.style.top = `${Math.max(0, startMinutes) / 60 * slotHeight}px`;
    element.style.height = `${Math.max(28, duration / 60 * slotHeight)}px`;
    return element;
  }

  function eventElement(event, compact) {
    const card = document.createElement('div');
    card.className = `ap-event${compact ? ' is-compact' : ''}`;
    const link = document.createElement('a');
    link.className = 'ap-event-main';
    link.href = event.url;
    link.setAttribute('aria-label', event.linked_url ? `Abrir ${event.reference}` : `Editar apontamento ${event.id}`);
    if (/^#[0-9A-Fa-f]{6}$/.test(event.color || '')) {
      card.style.backgroundColor = event.color;
      const rgb = [1, 3, 5].map(index => parseInt(event.color.slice(index, index + 2), 16));
      card.style.color = (rgb[0] * 299 + rgb[1] * 587 + rgb[2] * 114) / 1000 > 150 ? '#17202a' : '#ffffff';
    }
    const start = new Date(event.start.replace(' ', 'T'));
    const end = new Date(event.end.replace(' ', 'T'));
    const time = `${start.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' })}–${end.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' })}`;
    link.innerHTML = `<strong>${escapeText(time)}</strong>${event.reference ? `<small class="ap-event-reference">${escapeText(event.reference)}</small>` : ''}${event.appointment_type ? `<small class="ap-event-type">${escapeText(event.appointment_type)}</small>` : ''}${event.content ? `<span>${escapeText(event.content)}</span>` : ''}${event.project ? `<small>${escapeText(event.project)}</small>` : ''}${event.project_task ? `<small>${escapeText(event.project_task)}</small>` : ''}`;
    link.title = [time, event.reference, event.appointment_type, event.content, event.project, event.project_task].filter(Boolean).join(' · ');
    card.appendChild(link);

    const actions = document.createElement('span');
    actions.className = 'ap-event-actions';
    if (event.can_update) {
      const edit = document.createElement('a');
      edit.className = 'ap-event-action ap-event-edit';
      edit.href = event.edit_url;
      edit.title = 'Editar apontamento';
      edit.setAttribute('aria-label', `Editar apontamento ${event.id}`);
      edit.innerHTML = '<i class="ti ti-pencil"></i>';
      edit.addEventListener('click', actionEvent => actionEvent.stopPropagation());
      actions.appendChild(edit);
    }
    if (event.can_delete) {
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'ap-event-action ap-event-delete';
      remove.title = 'Excluir apontamento';
      remove.setAttribute('aria-label', `Excluir apontamento ${event.id}`);
      remove.innerHTML = '<i class="ti ti-trash"></i>';
      remove.addEventListener('click', async actionEvent => {
        actionEvent.preventDefault();
        actionEvent.stopPropagation();
        await deleteEvent(event);
      });
      actions.appendChild(remove);
    }
    if (actions.childElementCount) card.appendChild(actions);
    return card;
  }

  async function deleteEvent(event) {
    if (!window.confirm('Confirma a exclusão deste apontamento?')) return;
    hideMessage(errorBox);
    hideMessage(successBox);
    const payload = new URLSearchParams({ id: String(event.id), delete: '1', _glpi_csrf_token: csrfToken });
    try {
      const response = await fetch(deleteEndpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest',
          'X-Glpi-Csrf-Token': csrfToken,
        },
        body: payload.toString(),
      });
      const responseText = await response.text();
      let result;
      try {
        result = JSON.parse(responseText);
      } catch (_error) {
        throw new Error(`O servidor retornou uma resposta inválida (HTTP ${response.status}).`);
      }
      if (!response.ok || !result.success) throw new Error(result.error || `HTTP ${response.status}`);
      if (result.csrf_token) csrfToken = result.csrf_token;
      events = events.filter(item => Number(item.id) !== Number(event.id));
      // Reconsulte o servidor para atualizar também o total diário e o estado
      // da jornada. Apenas remover o cartão mantinha a cor calculada antes da
      // exclusão, deixando dias abaixo da meta indevidamente verdes.
      await loadEvents();
      showMessage(successBox, successText, result.message || 'Apontamento excluído com sucesso.');
    } catch (error) {
      showMessage(errorBox, errorText, error.message || 'Não foi possível excluir o apontamento.');
    }
  }

  function openCreate(date, startHour, endHour, startMinute = 0, endMinute = 0) {
    if (!canCreate) return;
    const start = new Date(date); start.setHours(startHour, startMinute, 0, 0);
    const end = new Date(date); end.setHours(endHour, endMinute, 0, 0);
    if (end <= start) end.setTime(start.getTime() + 3600000);
    openCreateModal(start, end, document.activeElement);
  }

  root.addEventListener('click', event => {
    const action = event.target.closest('[data-action]')?.dataset.action;
    const selectedView = event.target.closest('[data-view]')?.dataset.view;
    if (selectedView) {
      view = selectedView;
      loadEvents();
      return;
    }
    if (!action) return;
    if (action === 'open-create') {
      event.preventDefault();
      const start = new Date(cursor);
      start.setHours(9, 0, 0, 0);
      const end = new Date(start);
      end.setHours(10, 0, 0, 0);
      openCreateModal(start, end, event.target.closest('.ap-new'));
      return;
    }
    if (action === 'today') cursor = startOfDay(new Date());
    if (action === 'prev') cursor = view === 'month' ? new Date(cursor.getFullYear(), cursor.getMonth() - 1, 1) : addDays(cursor, view === 'week' ? -7 : -1);
    if (action === 'next') cursor = view === 'month' ? new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1) : addDays(cursor, view === 'week' ? 7 : 1);
    loadEvents();
  });
  root.querySelectorAll('.alert-dismissible .btn-close').forEach(button => {
    button.addEventListener('click', () => { button.closest('.alert').hidden = true; });
  });
  userFilter?.addEventListener('change', userSelectionChanged);
  document.addEventListener('select2:select', event => {
    if (event.target?.name === 'ap_user_filter') userSelectionChanged();
  });
  createForm?.addEventListener('submit', submitCreateModal);
  createForm?.addEventListener('input', () => {
    if (!settingModalValues) modalDirty = true;
  });
  createForm?.addEventListener('change', () => {
    if (!settingModalValues) modalDirty = true;
  });
  modalLinkType?.addEventListener('change', syncLinkedPicker);
  modalProject?.addEventListener('change', syncProjectTasks);
  detailsToggle?.addEventListener('click', () => {
    setDetailsExpanded(detailsToggle.getAttribute('aria-expanded') !== 'true');
  });
  createModal?.addEventListener('click', event => {
    const action = event.target.closest('[data-modal-action]')?.dataset.modalAction;
    if (action === 'cancel') closeCreateModal(false);
    if (action === 'continue-editing') closeDiscardConfirmation(true);
    if (action === 'discard') closeCreateModal(true);
  });
  document.addEventListener('keydown', event => {
    if (!createModal || createModal.hidden) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      if (discardConfirm && !discardConfirm.hidden) closeDiscardConfirmation(true);
      else closeCreateModal(false);
      return;
    }
    if (event.key !== 'Tab') return;
    const focusable = focusableModalElements();
    if (!focusable.length) {
      event.preventDefault();
      const activeDialog = discardConfirm && !discardConfirm.hidden ? discardDialog : createDialog;
      activeDialog?.focus();
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
  loadEvents();
})();
