<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { getClientCard } from '../lib/api/clients';
  import type { ClientCard } from '../lib/api/types';
  import Button from '../lib/components/ui/Button.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import StatusBadge from '../lib/components/StatusBadge.svelte';
  import { router } from '../lib/router.svelte';

  interface Props {
    id: number;
  }

  let { id }: Props = $props();

  let card = $state<ClientCard | null>(null);
  let loading = $state(true);
  let error = $state('');

  const eventTitles: Record<string, string> = {
    registered: 'Регистрация',
    activated: 'Регистрация подтверждена',
    rejected: 'Регистрация отклонена',
    password_reset: 'Сброс пароля',
    password_changed: 'Смена пароля',
    manager_assigned: 'Назначен менеджер',
    updated: 'Профиль изменён администратором',
    created: 'Создан администратором',
    blocked: 'Доступ заблокирован',
    unblocked: 'Доступ восстановлен',
    consent: 'Согласие на обработку данных'
  };

  const timeline = $derived(
    card
      ? [
          ...card.history.map((item) => ({
            id: `h${item.id}`,
            title: eventTitles[item.event] ?? item.event,
            actor: item.actor?.name ?? '',
            comment: item.comment,
            created_at: item.created_at
          })),
          ...(card.views ?? []).map((item) => ({
            id: `v${item.id}`,
            title: 'Открыл(а) профиль клиента',
            actor: item.user.name,
            comment: '',
            created_at: item.created_at
          }))
        ].sort((a, b) => (a.created_at < b.created_at ? 1 : -1))
      : []
  );

  const stateTitles: Record<string, string> = {
    active: 'активен',
    pending: 'ожидает подтверждения',
    rejected: 'отклонён'
  };

  onMount(() => {
    void load();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      card = await getClientCard(id);
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить клиента';
    } finally {
      loading = false;
    }
  }

  function formatDateTime(value: string): string {
    return value ? value.replace('T', ' ').slice(0, 16) : '';
  }
</script>

<section class="page">
  <div class="head">
    <Button variant="ghost" onclick={() => router.navigate('/clients')}>← Клиенты</Button>
  </div>

  {#if loading}
    <div class="center"><Spinner size={26} /></div>
  {:else if error}
    <div class="alert">{error}</div>
  {:else if card}
    <div class="card profile">
      <div class="row">
        <div>
          <h1>{card.client.name}</h1>
          <div class="meta">
            {#if card.client.company}<span>{card.client.company}</span>{/if}
            {#if card.client.position}<span>{card.client.position}</span>{/if}
          </div>
        </div>
        <span class="state" class:on={card.client.reg_state === 'active'}>
          {stateTitles[card.client.reg_state] ?? card.client.reg_state}
        </span>
      </div>

      <dl class="contacts">
        <div><dt>E-mail</dt><dd>{card.client.email || '—'}</dd></div>
        <div><dt>Телефон</dt><dd>{card.client.phone || '—'}</dd></div>
        <div><dt>ИНН</dt><dd>{card.client.inn || '—'}</dd></div>
        <div>
          <dt>Менеджер</dt>
          <dd>
            {#if card.client.manager}
              <a class="manager-link" href={`#/users/${card.client.manager.manager_id}`}>
                {card.client.manager.manager_name}
              </a>
            {:else}
              не назначен
            {/if}
          </dd>
        </div>
        <div><dt>Регистрация</dt><dd>{formatDateTime(card.client.registered_at)}</dd></div>
        <div>
          <dt>Последняя активность</dt>
          <dd>{card.client.last_seen_at ? formatDateTime(card.client.last_seen_at) : '—'}</dd>
        </div>
      </dl>
    </div>

    <div class="tiles">
      <div class="tile"><span class="value">{card.stats.total}</span><span class="label">всего заявок</span></div>
      <div class="tile"><span class="value">{card.stats.open}</span><span class="label">открыто</span></div>
      <div class="tile"><span class="value">{card.stats.closed}</span><span class="label">закрыто</span></div>
      <div class="tile"><span class="value warn">{card.stats.overdue}</span><span class="label">просрочено</span></div>
    </div>

    <div class="card">
      <h2>История пользователя</h2>
      {#if timeline.length === 0}
        <p class="empty">Событий нет</p>
      {:else}
        <ol class="timeline">
          {#each timeline as item (item.id)}
            <li>
              <div class="event">{item.title}</div>
              <div class="when">
                {formatDateTime(item.created_at)}
                {#if item.actor}· {item.actor}{/if}
              </div>
              {#if item.comment}<div class="comment">{item.comment}</div>{/if}
            </li>
          {/each}
        </ol>
      {/if}
    </div>

    <div class="card">
      <h2>Заявки клиента</h2>
      {#if card.requests.length === 0}
        <p class="empty">Заявок нет</p>
      {:else}
        <div class="requests">
          {#each card.requests as item (item.id)}
            <a class="request" href={`#/requests/${item.id}`}>
              <div class="line">
                <span class="number">{item.number}</span>
                <StatusBadge title={item.status.title} color={item.status.color} />
                {#if item.is_overdue}<span class="overdue">просрочена</span>{/if}
              </div>
              <div class="subject">{item.subject}</div>
              <div class="when">
                {formatDateTime(item.created_at)}
                {#if item.manager}· менеджер: {item.manager.name}{/if}
              </div>
            </a>
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
  }

  .head {
    display: flex;
    align-items: center;
    gap: var(--space-3);
  }

  h1 {
    margin: 0;
    font-size: 22px;
  }

  h2 {
    margin: 0 0 var(--space-3);
    font-size: 16px;
  }

  .card {
    padding: var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
  }

  .profile {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--space-3);
    flex-wrap: wrap;
  }

  .meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    color: var(--muted);
    font-size: 13px;
    margin-top: 4px;
  }

  .state {
    font-size: 12px;
    padding: 3px 10px;
    border-radius: 999px;
    border: 1px solid var(--border);
    color: var(--muted);
  }

  .state.on {
    border-color: #1e6b3a;
    color: #1e6b3a;
  }

  .contacts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: var(--space-3);
    margin: 0;
  }

  .contacts dt {
    font-size: 12px;
    color: var(--muted);
  }

  .contacts dd {
    margin: 2px 0 0;
    font-size: 14px;
  }

  .manager-link {
    color: var(--primary);
    text-decoration: none;
  }

  .manager-link:hover {
    text-decoration: underline;
  }

  .tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: var(--space-3);
  }

  .tile {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: var(--space-3);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
  }

  .value {
    font-size: 22px;
    font-weight: 600;
  }

  .value.warn {
    color: #b45309;
  }

  .label {
    font-size: 12px;
    color: var(--muted);
  }

  .timeline {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .timeline li {
    border-left: 2px solid var(--border);
    padding-left: var(--space-3);
  }

  .event {
    font-weight: 500;
    font-size: 14px;
  }

  .when {
    font-size: 12px;
    color: var(--muted);
  }

  .comment {
    margin-top: 2px;
    font-size: 13px;
  }

  .requests {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .request {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: var(--space-3);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    text-decoration: none;
    color: inherit;
  }

  .request:hover {
    border-color: var(--primary);
  }

  .line {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex-wrap: wrap;
  }

  .number {
    font-size: 13px;
    color: var(--muted);
  }

  .overdue {
    font-size: 12px;
    color: #b45309;
  }

  .subject {
    font-size: 14px;
    font-weight: 500;
  }

  .empty {
    color: var(--muted);
    margin: 0;
  }

  .alert {
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    background: var(--danger-bg);
    color: var(--danger);
    font-size: 13px;
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }
</style>
