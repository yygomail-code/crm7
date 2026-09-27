<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { getUserProfile, type UserProfile } from '../lib/api/profile';
  import { startThread } from '../lib/api/chat';
  import { avatarColor, avatarLetter, avatarName } from '../lib/avatar';
  import { avatars } from '../lib/stores/avatar.svelte';
  import { auth } from '../lib/stores/auth.svelte';
  import { router } from '../lib/router.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';

  interface Props {
    id: number;
  }

  let { id }: Props = $props();

  let profile = $state<UserProfile | null>(null);
  const avatarUrl = $derived(avatars.url(id));
  let loading = $state(true);
  let error = $state('');
  let chatBusy = $state(false);

  const roleTitle = $derived(
    profile === null ? '' : profile.level >= 50 ? 'Администратор' : profile.is_staff ? 'Менеджер' : 'Клиент'
  );

  const avatarSeed = $derived(avatarName(profile?.name, profile?.login ?? id));
  const letter = $derived(avatarLetter(avatarSeed));
  const avatarBg = $derived(avatarColor(avatarSeed));

  const isSelf = $derived(auth.user?.id === id);
  const canChat = $derived((auth.user?.level ?? 0) >= 10 && !isSelf && (profile?.is_staff ?? false));

  async function openChat(): Promise<void> {
    chatBusy = true;
    error = '';

    try {
      const thread = await startThread(id);
      router.navigate(`/chat?thread=${thread.id}`);
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось открыть чат';
    } finally {
      chatBusy = false;
    }
  }

  onMount(() => {
    void load();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      profile = await getUserProfile(id);
      await avatars.load(id);
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить профиль';
    } finally {
      loading = false;
    }
  }
</script>

<section class="page">
  <div class="head">
    <Button variant="ghost" onclick={() => window.history.back()}>← Назад</Button>
  </div>

  {#if loading}
    <div class="center"><Spinner size={26} /></div>
  {:else if error}
    <div class="alert">{error}</div>
  {:else if profile}
    <div class="card profile">
      <div class="avatar">
        {#if avatarUrl}
          <img src={avatarUrl} alt="" />
        {:else}
          <span style:background={avatarBg}>{letter}</span>
        {/if}
      </div>

      <div class="info">
        <h1>{profile.name}</h1>
        <div class="role">{roleTitle}</div>
        {#if profile.position}<div class="position">{profile.position}</div>{/if}
      </div>
    </div>

    <div class="card">
      <h2>Контакты</h2>
      <dl class="contacts">
        <div>
          <dt>E-mail</dt>
          <dd>
            {#if profile.email}
              <a href={`mailto:${profile.email}`}>{profile.email}</a>
            {:else}—{/if}
          </dd>
        </div>
        <div>
          <dt>Телефон</dt>
          <dd>
            {#if profile.phone}
              <a href={`tel:${profile.phone}`}>{profile.phone}</a>
            {:else}—{/if}
          </dd>
        </div>
        {#if profile.company}
          <div><dt>Компания</dt><dd>{profile.company}</dd></div>
        {/if}
      </dl>

      {#if canChat}
        <div class="actions">
          <Button variant="ghost" loading={chatBusy} onclick={() => void openChat()}>
            Написать в чат
          </Button>
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
    width: 100%;
    max-width: 720px;
    margin: 0 auto;
  }

  .head {
    display: flex;
    align-items: center;
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
    align-items: center;
    gap: var(--space-4);
  }

  .avatar {
    width: 96px;
    height: 96px;
    flex: 0 0 auto;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: color-mix(in srgb, var(--primary) 10%, white);
    color: var(--primary);
    font-size: 30px;
    font-weight: 600;
    overflow: hidden;
    border: 1px solid var(--border);
  }

  .avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .avatar span {
    display: grid;
    place-items: center;
    width: 100%;
    height: 100%;
    color: #fff;
  }

  h1 {
    margin: 0;
    font-size: 22px;
  }

  h2 {
    margin: 0 0 var(--space-3);
    font-size: 16px;
  }

  .role {
    margin-top: 4px;
    display: inline-block;
    font-size: 12px;
    padding: 2px 10px;
    border: 1px solid var(--border);
    border-radius: 999px;
    color: var(--muted);
  }

  .position {
    margin-top: 4px;
    font-size: 13px;
    color: var(--muted);
  }

  .contacts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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

  .contacts a {
    color: var(--primary);
    text-decoration: none;
  }

  .actions {
    display: flex;
    gap: var(--space-2);
    margin-top: var(--space-3);
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
