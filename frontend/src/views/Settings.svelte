<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    getEmailSettings,
    getSystemSettings,
    listQueue,
    listTemplates,
    saveEmailSettings,
    saveSystemSettings,
    saveTemplate,
    sendTestEmail,
    testEmailConnection
  } from '../lib/api/admin';
  import {
    deleteSchedule,
    listSchedules,
    saveSchedule,
    sendScheduleNow
  } from '../lib/api/schedules';
  import { listManagers } from '../lib/api/requests';
  import type {
    EmailQueueItem,
    EmailSettings,
    EmailTemplate,
    ManagerItem,
    ReportSchedule,
    SystemSettings
  } from '../lib/api/types';
  import Button from '../lib/components/ui/Button.svelte';
  import Input from '../lib/components/ui/Input.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { formatDateTime } from '../lib/format';

  let settings = $state<EmailSettings | null>(null);
  let templates = $state<EmailTemplate[]>([]);
  let queue = $state<EmailQueueItem[]>([]);
  let loading = $state(true);
  let error = $state('');
  let notice = $state('');
  let busy = $state(false);

  let password = $state('');
  let portInput = $state('465');
  let testTo = $state('');
  let templateCode = $state('');
  let templateSubject = $state('');
  let templateBody = $state('');

  let system = $state<SystemSettings | null>(null);
  let slaReaction = $state('2');
  let slaResolution = $state('24');
  let spfDone = $state(false);

  let schedules = $state<ReportSchedule[]>([]);
  let managers = $state<ManagerItem[]>([]);
  let editingId = $state<number | null>(null);
  let scheduleTitle = $state('');
  let scheduleType = $state('summary');
  let scheduleFrequency = $state('daily');
  let scheduleTime = $state('09:00');
  let scheduleDayOfWeek = $state('1');
  let scheduleDayOfMonth = $state('1');
  let scheduleRecipients = $state<number[]>([]);
  let scheduleExtra = $state('');
  let scheduleActive = $state(true);

  onMount(() => {
    void load();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      settings = await getEmailSettings();
      portInput = String(settings.port);

      system = await getSystemSettings();
      slaReaction = String(system.sla_reaction_hours);
      slaResolution = String(system.sla_resolution_hours);
      spfDone = system.spf_checklist;

      templates = await listTemplates();
      queue = await listQueue();
      schedules = await listSchedules();
      managers = (await listManagers()).items;

      if (templates.length > 0) {
        selectTemplate(templates[0]);
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить настройки';
    } finally {
      loading = false;
    }
  }

  function applyPreset(code: string): void {
    if (!settings) return;

    const preset = settings.presets.find((item) => item.code === code);

    if (!preset) return;

    settings = {
      ...settings,
      host: preset.host,
      port: preset.port,
      encryption: preset.encryption
    };
    portInput = String(preset.port);
  }

  function selectTemplate(template: EmailTemplate): void {
    templateCode = template.code;
    templateSubject = template.subject;
    templateBody = template.body;
  }

async function saveSystem(): Promise<void> {
  busy = true;
  error = '';
  notice = '';

  try {
    system = await saveSystemSettings({
      sla_reaction_hours: Number(slaReaction) || 2,
      sla_resolution_hours: Number(slaResolution) || 24,
      spf_checklist: spfDone
    });

    slaReaction = String(system.sla_reaction_hours);
    slaResolution = String(system.sla_resolution_hours);
    notice = 'Настройки системы сохранены';
  } catch (cause) {
    error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить настройки';
  } finally {
    busy = false;
  }
}

async function saveSettings(): Promise<void> {
  if (!settings) return;

    busy = true;
    error = '';
    notice = '';

    try {
      settings = await saveEmailSettings({
        enabled: settings.enabled,
        host: settings.host,
        port: Number(portInput) || 0,
        encryption: settings.encryption,
        username: settings.username,
        password: password !== '' ? password : undefined,
        from: settings.from,
        from_name: settings.from_name
      });
      password = '';
      notice = 'Настройки сохранены';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить настройки';
    } finally {
      busy = false;
    }
  }

  async function checkConnection(): Promise<void> {
    busy = true;
    error = '';
    notice = '';

    try {
      await testEmailConnection();
      notice = 'Соединение с SMTP установлено';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось подключиться';
    } finally {
      busy = false;
    }
  }

  async function sendTest(): Promise<void> {
    busy = true;
    error = '';
    notice = '';

    try {
      await sendTestEmail(testTo.trim());
      notice = 'Тестовое письмо отправлено';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось отправить письмо';
    } finally {
      busy = false;
    }
  }

  function resetScheduleForm(): void {
    editingId = null;
    scheduleTitle = '';
    scheduleType = 'summary';
    scheduleFrequency = 'daily';
    scheduleTime = '09:00';
    scheduleDayOfWeek = '1';
    scheduleDayOfMonth = '1';
    scheduleRecipients = [];
    scheduleExtra = '';
    scheduleActive = true;
  }

  function startEdit(schedule: ReportSchedule): void {
    editingId = schedule.id;
    scheduleTitle = schedule.title;
    scheduleType = schedule.report_type;
    scheduleFrequency = schedule.frequency;
    scheduleTime = schedule.time_of_day;
    scheduleDayOfWeek = String(schedule.day_of_week ?? 1);
    scheduleDayOfMonth = String(schedule.day_of_month ?? 1);
    scheduleRecipients = schedule.recipients.map((item) => item.id);
    scheduleExtra = schedule.extra_emails.join(', ');
    scheduleActive = schedule.is_active;
  }

  async function submitSchedule(): Promise<void> {
    busy = true;
    error = '';
    notice = '';

    try {
      schedules = await saveSchedule(editingId, {
        title: scheduleTitle.trim(),
        report_type: scheduleType,
        frequency: scheduleFrequency,
        time_of_day: scheduleTime,
        day_of_week: scheduleFrequency === 'weekly' ? Number(scheduleDayOfWeek) : null,
        day_of_month: scheduleFrequency === 'monthly' ? Number(scheduleDayOfMonth) : null,
        recipients: scheduleRecipients,
        extra_emails: scheduleExtra
          .split(',')
          .map((item) => item.trim())
          .filter((item) => item !== ''),
        is_active: scheduleActive
      });
      resetScheduleForm();
      notice = 'Расписание сохранено';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить расписание';
    } finally {
      busy = false;
    }
  }

  async function removeSchedule(id: number): Promise<void> {
    busy = true;
    error = '';

    try {
      await deleteSchedule(id);
      schedules = await listSchedules();
      notice = 'Расписание удалено';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось удалить расписание';
    } finally {
      busy = false;
    }
  }

  async function sendSchedule(id: number): Promise<void> {
    busy = true;
    error = '';
    notice = '';

    try {
      const result = await sendScheduleNow(id);
      schedules = await listSchedules();
      notice = `Отчёт отправлен получателям: ${result.sent}`;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось отправить отчёт';
    } finally {
      busy = false;
    }
  }

  function frequencyLabel(schedule: ReportSchedule): string {
    if (schedule.frequency === 'weekly') return 'еженедельно';
    if (schedule.frequency === 'monthly') return 'ежемесячно';

    return 'ежедневно';
  }

  async function saveCurrentTemplate(): Promise<void> {
    if (!templateCode) return;

    busy = true;
    error = '';
    notice = '';

    try {
      templates = await saveTemplate(templateCode, templateSubject, templateBody);
      notice = 'Шаблон сохранён';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить шаблон';
    } finally {
      busy = false;
    }
  }
</script>

<section class="page">
  <h1>Настройки</h1>

  {#if error}
    <div class="alert">{error}</div>
  {/if}
  {#if notice}
    <div class="notice">{notice}</div>
  {/if}

  {#if loading}
    <div class="center"><Spinner size={26} /></div>
  {:else if settings}
    <div class="card">
      <h2>SMTP</h2>

      <label class="checkbox">
        <input type="checkbox" bind:checked={settings.enabled} />
        Отправлять письма (уведомления по заявкам)
      </label>

      <div class="presets">
        {#each settings.presets as preset}
          <Button variant="ghost" onclick={() => applyPreset(preset.code)}>{preset.title}</Button>
        {/each}
      </div>

      <div class="grid">
        <Input label="SMTP-хост" bind:value={settings.host} placeholder="smtp.yandex.ru" />
        <Input label="Порт" type="number" bind:value={portInput} />
        <label class="field">
          <span class="label">Шифрование</span>
          <select bind:value={settings.encryption}>
            <option value="ssl">SSL (465)</option>
            <option value="tls">STARTTLS (587)</option>
            <option value="none">Без шифрования</option>
          </select>
        </label>
        <Input label="Логин" bind:value={settings.username} placeholder="user@yandex.ru" />
        <Input
          label={settings.has_password ? 'Пароль (задан, оставьте пустым чтобы не менять)' : 'Пароль'}
          type="password"
          bind:value={password}
          autocomplete="new-password"
        />
        <Input label="Адрес отправителя" bind:value={settings.from} placeholder="user@yandex.ru" />
        <Input label="Имя отправителя" bind:value={settings.from_name} placeholder="CRM" />
      </div>

      <div class="actions">
        <Button loading={busy} onclick={saveSettings}>Сохранить</Button>
        <Button variant="ghost" loading={busy} onclick={checkConnection}>Проверить соединение</Button>
      </div>

      <div class="test">
        <Input label="Тестовое письмо на адрес" bind:value={testTo} placeholder="you@example.com" />
        <Button variant="ghost" loading={busy} disabled={!testTo.trim()} onclick={sendTest}>
          Отправить тест
        </Button>
      </div>

      <p class="hint">
        Письма уходят через очередь: cron-задача <code>php api/bin/cron.php</code> раз в минуту.
      </p>
    </div>

    <div class="card">
      <h2>Система</h2>

      <div class="grid">
        <Input label="SLA: срок реакции, часов" type="number" bind:value={slaReaction} />
        <Input label="SLA: срок решения, часов" type="number" bind:value={slaResolution} />
      </div>

      <label class="checkbox">
        <input type="checkbox" bind:checked={spfDone} />
        SPF/DKIM/DMARC для домена настроены
      </label>

      {#if system}
        <ul class="steps">
          {#each system.spf_steps as step}
            <li>{step}</li>
          {/each}
        </ul>
      {/if}

      <div class="actions">
        <Button loading={busy} onclick={() => void saveSystem()}>Сохранить</Button>
      </div>
    </div>

    <div class="card">
      <h2>Шаблоны писем</h2>
      <p class="hint">Подстановки: {'{title}'}, {'{body}'}, {'{request_number}'}, {'{link}'}</p>

      <div class="templates">
        {#each templates as template}
          <button
            type="button"
            class="template-item"
            class:active={template.code === templateCode}
            onclick={() => selectTemplate(template)}
          >
            {template.code}
          </button>
        {/each}
      </div>

      {#if templateCode}
        <Input label="Тема" bind:value={templateSubject} />
        <label class="field">
          <span class="label">Текст письма</span>
          <textarea bind:value={templateBody} rows="8"></textarea>
        </label>
        <div class="actions">
          <Button loading={busy} onclick={saveCurrentTemplate}>Сохранить шаблон</Button>
        </div>
      {/if}
    </div>

    <div class="card">
      <h2>Расписание отчётов</h2>
      <p class="hint">
        Отчёты отправляются cron-задачей: кому, когда и какой отчёт. Получатели — сотрудники с
        заполненным e-mail.
      </p>

      {#if schedules.length === 0}
        <p class="hint">Расписаний пока нет</p>
      {:else}
        <div class="schedules">
          {#each schedules as schedule (schedule.id)}
            <div class="schedule-row">
              <div class="s-info">
                <span class="s-title">{schedule.title}</span>
                <span class="s-meta">
                  {schedule.report_type === 'managers' ? 'по менеджерам' : 'сводка по заявкам'} ·
                  {frequencyLabel(schedule)} · {schedule.time_of_day} · получатели:
                  {schedule.recipients.map((item) => item.name).join(', ') || '—'}
                  {schedule.extra_emails.length > 0 ? ', ' + schedule.extra_emails.join(', ') : ''}
                </span>
                <span class="s-meta">
                  {schedule.is_active ? 'активно' : 'выключено'}
                  {#if schedule.last_sent_at}
                    · последняя отправка {formatDateTime(schedule.last_sent_at)}
                  {/if}
                </span>
              </div>
              <div class="s-actions">
                <Button variant="ghost" disabled={busy} onclick={() => startEdit(schedule)}>
                  Изменить
                </Button>
                <Button variant="ghost" loading={busy} onclick={() => void sendSchedule(schedule.id)}>
                  Отправить сейчас
                </Button>
                <button
                  type="button"
                  class="s-del"
                  disabled={busy}
                  onclick={() => void removeSchedule(schedule.id)}
                >
                  Удалить
                </button>
              </div>
            </div>
          {/each}
        </div>
      {/if}

      <h3>{editingId === null ? 'Новое расписание' : 'Изменение расписания'}</h3>

      <div class="grid">
        <Input label="Название" bind:value={scheduleTitle} placeholder="Утренняя сводка" />

        <label class="field">
          <span class="label">Отчёт</span>
          <select bind:value={scheduleType}>
            <option value="summary">Сводка по заявкам</option>
            <option value="managers">По менеджерам</option>
          </select>
        </label>

        <label class="field">
          <span class="label">Периодичность</span>
          <select bind:value={scheduleFrequency}>
            <option value="daily">Ежедневно</option>
            <option value="weekly">Еженедельно</option>
            <option value="monthly">Ежемесячно</option>
          </select>
        </label>

        <Input label="Время (ЧЧ:ММ)" type="time" bind:value={scheduleTime} />

        {#if scheduleFrequency === 'weekly'}
          <label class="field">
            <span class="label">День недели</span>
            <select bind:value={scheduleDayOfWeek}>
              <option value="1">Понедельник</option>
              <option value="2">Вторник</option>
              <option value="3">Среда</option>
              <option value="4">Четверг</option>
              <option value="5">Пятница</option>
              <option value="6">Суббота</option>
              <option value="7">Воскресенье</option>
            </select>
          </label>
        {/if}

        {#if scheduleFrequency === 'monthly'}
          <label class="field">
            <span class="label">День месяца</span>
            <select bind:value={scheduleDayOfMonth}>
              {#each Array.from({ length: 28 }, (_, index) => String(index + 1)) as day}
                <option value={day}>{day}</option>
              {/each}
            </select>
          </label>
        {/if}

        <label class="field">
          <span class="label">Получатели (сотрудники)</span>
          <select multiple bind:value={scheduleRecipients} size="4">
            {#each managers as manager}
              <option value={manager.id}>
                {manager.name}{manager.email ? ` (${manager.email})` : ''}
              </option>
            {/each}
          </select>
        </label>

        <Input label="Дополнительные e-mail (через запятую)" bind:value={scheduleExtra} />
      </div>

      <label class="checkbox">
        <input type="checkbox" bind:checked={scheduleActive} />
        Активно
      </label>

      <div class="actions">
        <Button loading={busy} disabled={!scheduleTitle.trim()} onclick={submitSchedule}>
          {editingId === null ? 'Добавить расписание' : 'Сохранить изменения'}
        </Button>
        {#if editingId !== null}
          <Button variant="ghost" onclick={resetScheduleForm}>Отмена</Button>
        {/if}
      </div>
    </div>

    <div class="card">
      <h2>Очередь отправки</h2>
      {#if queue.length === 0}
        <p class="hint">Очередь пуста</p>
      {:else}
        <div class="queue">
          {#each queue as item (item.id)}
            <div class="queue-row">
              <span class="q-to">{item.to}</span>
              <span class="q-subject">{item.subject}</span>
              <span class="q-status" class:failed={item.status === 'failed'} class:pending={item.status === 'pending'}>
                {item.status}
              </span>
              <span class="q-date">{formatDateTime(item.sent_at ?? item.created_at)}</span>
            </div>
            {#if item.last_error}
              <div class="q-error">{item.last_error}</div>
            {/if}
          {/each}
        </div>
      {/if}
    </div>
  {/if}
</section>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    max-width: 900px;
  }

  h1 {
    margin: 0;
    font-size: 22px;
  }

  h2 {
    margin: 0;
    font-size: 16px;
  }

  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: var(--space-5);
    box-shadow: var(--shadow-sm);
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: var(--space-3);
  }

  .field {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
  }

  .label {
    font-size: 13px;
    color: var(--muted);
  }

  select,
  textarea {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font-family: inherit;
    font-size: inherit;
    outline: none;
    resize: vertical;
  }

  select:focus,
  textarea:focus {
    border-color: var(--primary);
  }

  .checkbox {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: 14px;
  }

  .presets,
  .actions,
  .test {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    align-items: flex-end;
  }

  .templates {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  h3 {
    margin: var(--space-3) 0 0;
    font-size: 14px;
  }

  .schedules {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .schedule-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: var(--space-3);
  }

  .s-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .s-title {
    font-weight: 500;
  }

  .s-meta {
    font-size: 12px;
    color: var(--muted);
  }

  .s-actions {
    display: flex;
    gap: var(--space-2);
    align-items: center;
  }

  .s-del {
    border: none;
    background: none;
    color: var(--danger);
    cursor: pointer;
    font-size: 13px;
  }

  .template-item {
    padding: 4px 10px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface);
    font-size: 12px;
    cursor: pointer;
    color: var(--muted);
  }

  .template-item.active {
    border-color: var(--primary);
    color: var(--primary);
  }

  .queue {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .queue-row {
    display: grid;
    grid-template-columns: 1.2fr 2fr 80px 120px;
    gap: var(--space-2);
    font-size: 13px;
    align-items: center;
  }

  .q-to,
  .q-subject {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .q-status {
    font-size: 12px;
    border-radius: 999px;
    padding: 1px 8px;
    text-align: center;
    background: #ecfdf5;
    color: #047857;
  }

  .q-status.pending {
    background: #fffbeb;
    color: #b45309;
  }

  .q-status.failed {
    background: var(--danger-bg);
    color: var(--danger);
  }

  .q-date {
    color: var(--muted);
    font-size: 12px;
  }

  .q-error {
    font-size: 12px;
    color: var(--danger);
  }

  .hint {
    color: var(--muted);
    font-size: 13px;
    margin: 0;
  }

  .alert {
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    background: var(--danger-bg);
    color: var(--danger);
    font-size: 13px;
  }

  .notice {
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    background: #ecfdf5;
    color: #047857;
    font-size: 13px;
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }
</style>
