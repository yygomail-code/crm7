<script lang="ts">
  import { onMount } from 'svelte';
  import { auth } from './lib/stores/auth.svelte';
  import { appSettings } from './lib/stores/app-settings.svelte';
  import { matchRoute, router } from './lib/router.svelte';
  import Spinner from './lib/components/ui/Spinner.svelte';
  import CookieNotice from './lib/components/CookieNotice.svelte';
  import AppShell from './lib/components/layout/AppShell.svelte';
  import Login from './views/Login.svelte';
  import Register from './views/Register.svelte';
  import ForgotPassword from './views/ForgotPassword.svelte';
  import ResetPassword from './views/ResetPassword.svelte';
  import Requests from './views/Requests.svelte';
  import RequestCard from './views/RequestCard.svelte';
  import RequestNew from './views/RequestNew.svelte';
  import Cart from './views/Cart.svelte';
  import Clients from './views/Clients.svelte';
  import ClientCard from './views/ClientCard.svelte';
  import Chat from './views/Chat.svelte';
  import Stocks from './views/Stocks.svelte';
  import Admin from './views/Admin.svelte';
  import Legal from './views/Legal.svelte';
  import ManagerProfile from './views/ManagerProfile.svelte';
  import Notifications from './views/Notifications.svelte';
  import Reports from './views/Reports.svelte';
  import Settings from './views/Settings.svelte';
  import Substitutions from './views/Substitutions.svelte';
  import Profile from './views/Profile.svelte';
  import MyReports from './views/MyReports.svelte';
  import About from './views/About.svelte';
  import NotFound from './views/NotFound.svelte';

  onMount(() => {
    void auth.bootstrap();
  });

  $effect(() => {
    if (auth.isAuthenticated && appSettings.loadedFor !== auth.user?.id) {
      void appSettings.load();
    }
  });

  $effect(() => {
    if (auth.isAuthenticated && router.current.path === '/') {
      router.navigate('/requests', true);
    }
  });

  const route = $derived(router.current.path);
  const requestMatch = $derived(matchRoute('/requests/:id', route));
  const clientMatch = $derived(matchRoute('/clients/:id', route));
  const legalMatch = $derived(matchRoute('/legal/:code', route));
  const userMatch = $derived(matchRoute('/users/:id', route));
</script>

{#if !auth.ready}
  <div class="boot">
    <Spinner size={28} />
  </div>
{:else if !auth.isAuthenticated}
  {#if legalMatch}
    <Legal code={legalMatch.code} />
  {:else if route === '/forgot'}
    <ForgotPassword />
  {:else if route === '/reset'}
    <ResetPassword />
  {:else if route === '/register'}
    <Register />
  {:else}
    <Login />
  {/if}
{:else}
  <AppShell>
    {#if route === '/' || route === '/requests'}
      <Requests />
    {:else if route === '/requests/new'}
      <RequestNew />
    {:else if route === '/cart'}
      <Cart />
    {:else if requestMatch}
      <RequestCard id={Number(requestMatch.id)} />
    {:else if route === '/clients'}
      <Clients />
    {:else if clientMatch}
      <ClientCard id={Number(clientMatch.id)} />
    {:else if route === '/chat'}
      <Chat />
    {:else if route === '/stocks'}
      <Stocks />
    {:else if route === '/reports'}
      <Reports />
    {:else if route === '/substitutions'}
      <Substitutions />
    {:else if route === '/notifications'}
      <Notifications />
    {:else if route === '/settings'}
      <Settings />
    {:else if userMatch}
      <ManagerProfile id={Number(userMatch.id)} />
    {:else if legalMatch}
      <Legal code={legalMatch.code} />
    {:else if route === '/admin'}
      <Admin />
    {:else if route === '/my-reports'}
      <MyReports />
    {:else if route === '/profile'}
      <Profile />
    {:else if route === '/about'}
      <About />
    {:else}
      <NotFound />
    {/if}
  </AppShell>
{/if}

<CookieNotice />

<style>
  .boot {
    min-height: 100vh;
    display: grid;
    place-items: center;
  }
</style>
