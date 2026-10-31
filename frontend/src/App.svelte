<script lang="ts">
  import { onMount } from 'svelte';
  import { auth } from './lib/stores/auth.svelte';
  import { appSettings } from './lib/stores/app-settings.svelte';
  import { filterPrefs } from './lib/stores/filter-prefs.svelte';
  import { matchRoute, router } from './lib/router.svelte';
  import Spinner from './lib/components/ui/Spinner.svelte';
  import CookieNotice from './lib/components/CookieNotice.svelte';
  import AppShell from './lib/components/layout/AppShell.svelte';
  import PageBadge from './lib/components/dev/PageBadge.svelte';
  import { badgeForRoute } from './lib/dev/page-badges';
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
  import StockGroups from './views/StockGroups.svelte';
  import StockGroupEdit from './views/StockGroupEdit.svelte';
  import Warehouses from './views/Warehouses.svelte';
  import WarehouseCard from './views/WarehouseCard.svelte';
  import WarehouseView from './views/WarehouseView.svelte';
  import ItemEdit from './views/ItemEdit.svelte';
  import ItemCard from './views/ItemCard.svelte';
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
    if (!auth.isAuthenticated && appSettings.loadedFor !== null) {
      appSettings.reset();
    }
  });

  $effect(() => {
    if (auth.isAuthenticated && appSettings.loadedFor !== auth.user?.id) {
      void appSettings.load();
    }
  });

  $effect(() => {
    const userId = auth.user?.id ?? null;

    if (auth.isAuthenticated && userId !== null && filterPrefs.loadedFor !== userId) {
      void filterPrefs.load(userId);
    }
  });

  $effect(() => {
    if (auth.isAuthenticated && router.current.path === '/') {
      router.navigate('/requests', true);
    }
  });

  const route = $derived(router.current.path);
  const devBadge = $derived(import.meta.env.DEV ? badgeForRoute(route) : null);
  const requestMatch = $derived(matchRoute('/requests/:id', route));
  const clientMatch = $derived(matchRoute('/clients/:id', route));
  const legalMatch = $derived(matchRoute('/legal/:code', route));
  const userMatch = $derived(matchRoute('/users/:id', route));
  const itemNewMatch = $derived(matchRoute('/stocks/items/new', route));
  const itemEditMatch = $derived(matchRoute('/stocks/items/:id/edit', route));
  const itemCardMatch = $derived(matchRoute('/stocks/items/:id', route));
  const groupEditMatch = $derived(matchRoute('/stocks/groups/:id', route));
  const warehouseMatch = $derived(matchRoute('/warehouses/:id', route));
  const warehouseEditMatch = $derived(matchRoute('/warehouses/:id/edit', route));
</script>

{#if !auth.ready || (auth.isAuthenticated && (filterPrefs.loadedFor !== auth.user?.id || appSettings.loadedFor !== auth.user?.id))}
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
    {:else if (route === '/stocks/groups' || route === '/stocks/groups/new' || groupEditMatch) && !auth.can('settings.manage')}
      <NotFound />
    {:else if route === '/stocks/groups'}
      <StockGroups />
    {:else if route === '/stocks/groups/new'}
      <StockGroupEdit id={0} />
    {:else if groupEditMatch}
      <StockGroupEdit id={Number(groupEditMatch.id)} />
    {:else if route === '/warehouses'}
      <Warehouses />
    {:else if route === '/warehouses/new'}
      <WarehouseCard id={0} />
    {:else if warehouseEditMatch}
      <WarehouseCard id={Number(warehouseEditMatch.id)} />
    {:else if warehouseMatch}
      <WarehouseView id={Number(warehouseMatch.id)} />
    {:else if itemNewMatch}
      <ItemEdit id={0} />
    {:else if itemEditMatch}
      <ItemEdit id={Number(itemEditMatch.id)} />
    {:else if itemCardMatch}
      <ItemCard id={Number(itemCardMatch.id)} />
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

{#if devBadge}
  <PageBadge id={devBadge.id} task={devBadge.task} />
{/if}

<style>
  .boot {
    min-height: 100vh;
    display: grid;
    place-items: center;
  }
</style>
