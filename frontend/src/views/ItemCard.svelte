<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { getStockLevel, loadItemPhotoUrl } from '../lib/api/stocks';
  import { listPriceTypes } from '../lib/api/prices';
  import type { PriceListItem, PriceType, StockLevel, StockLevelEntry } from '../lib/api/types';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { auth } from '../lib/stores/auth.svelte';
  import { cart } from '../lib/stores/cart.svelte';
  import { formatPrice, stockQuantityText } from '../lib/format';
  import { router } from '../lib/router.svelte';

  interface Props {
    id: number;
  }

  let { id }: Props = $props();

  let loading = $state(true);
  let error = $state('');
  let item = $state<StockLevel | null>(null);
  let canEdit = $state(false);
  let pricesEnabled = $state(false);
  let groupsEnabled = $state(false);
  let itemTypes = $state<{ code: string; title: string }[]>([]);
  let priceTypes = $state<PriceType[]>([]);

  let tab = $state<'main' | 'levels' | 'composition'>('main');

  const canCreate = $derived(auth.can('requests.create') && appSettings.cartEnabled);
  const isSet = $derived(item?.type === 'set');
  const levels = $derived(item?.levels ?? []);
  const composition = $derived(item?.composition ?? []);
  const totalQuantity = $derived(levels.reduce((sum, level) => sum + level.quantity, 0));
  const priceColumns = $derived(
    priceTypes.filter((type) => levels.some((level) => (level.price_type_ids ?? []).includes(type.id)))
  );
  const typeTitle = $derived(
    item?.type ? (itemTypes.find((type) => type.code === item?.type)?.title ?? item.type) : ''
  );

  const photos = $derived(item?.photos ?? []);
  const photoAspect = $derived(appSettings.photoAspect);
  const photoFit = $derived(appSettings.photoFit);

  let photoIndex = $state(0);
  let lightboxOpen = $state(false);
  let photoUrls = $state<Record<string, string | null>>({});

  const currentPhoto = $derived(photos[photoIndex] ?? null);
  const mainPhotoUrl = $derived(currentPhoto ? (photoUrls[`${currentPhoto.id}:card`] ?? null) : null);
  const lightboxUrl = $derived(
    currentPhoto
      ? (photoUrls[`${currentPhoto.id}:max`] ?? photoUrls[`${currentPhoto.id}:card`] ?? null)
      : null
  );

  $effect(() => {
    for (const photo of photos) {
      for (const size of ['preview', 'card'] as const) {
        const key = `${photo.id}:${size}`;

        if (photoUrls[key] === undefined) {
          void loadItemPhotoUrl(photo.id, size).then((url) => {
            photoUrls[key] = url;
          });
        }
      }
    }
  });

  $effect(() => {
    if (!lightboxOpen) {
      return;
    }

    for (const photo of photos) {
      const key = `${photo.id}:max`;

      if (photoUrls[key] === undefined) {
        void loadItemPhotoUrl(photo.id, 'max').then((url) => {
          photoUrls[key] = url;
        });
      }
    }
  });

  $effect(() => {
    if (photoIndex >= photos.length) {
      photoIndex = 0;
    }
  });

  function prevPhoto(): void {
    if (photos.length > 1) {
      photoIndex = (photoIndex - 1 + photos.length) % photos.length;
    }
  }

  function nextPhoto(): void {
    if (photos.length > 1) {
      photoIndex = (photoIndex + 1) % photos.length;
    }
  }

  function thumbUrl(photoId: number): string | null {
    return photoUrls[`${photoId}:preview`] ?? null;
  }

  function typeTitleOf(code: string): string {
    return itemTypes.find((type) => type.code === code)?.title ?? code;
  }

  function priceRows(target: StockLevel | null): PriceListItem[] {
    if (target === null) {
      return [];
    }

    if (target.price_list && target.price_list.length > 0) {
      return target.price_list;
    }

    if (target.price !== null && target.price !== undefined) {
      return [{ id: 0, title: 'Цена', is_default: true, price: target.price }];
    }

    return [];
  }

  function addLevelToCart(target: StockLevel, level: StockLevelEntry): void {
    cart.add({
      warehouseId: level.warehouse_id,
      warehouseName: level.warehouse_name,
      name: target.name,
      unit: level.unit || target.unit,
      quantity: 1,
      stockLevelId: level.id > 0 ? level.id : null,
      price: null
    });
  }

  onMount(() => {
    void load();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      const data = await getStockLevel(id);
      item = data.item;
      canEdit = data.can_edit;
      pricesEnabled = data.prices_enabled;
      groupsEnabled = data.groups_enabled;
      itemTypes = data.item_types ?? [];

      if (pricesEnabled) {
        priceTypes = (await listPriceTypes().catch(() => ({ items: [] }))).items;
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить позицию';
    } finally {
      loading = false;
    }
  }
</script>

<section class="page">
  <div class="head">
    <button
      type="button"
      class="back"
      title="Назад к номенклатуре"
      aria-label="Назад к номенклатуре"
      onclick={() => router.navigate('/stocks')}
    >
      <Icon name="arrow-left" size={16} />
    </button>
    <div class="head-text">
      <h1>
        {item?.name ?? 'Позиция'}
        {#if item && item.active === false}
          <span class="status off">деактивирована</span>
        {/if}
      </h1>
    </div>
    {#if item && canEdit}
      <div class="head-actions">
        <Button variant="ghost" onclick={() => router.navigate(`/stocks/items/${id}/edit`)}>
          <Icon name="edit" size={16} /> Редактировать
        </Button>
      </div>
    {/if}
  </div>

  {#if error}
    <div class="alert" role="alert">
      <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
      <span>{error}</span>
    </div>
  {/if}

  {#if loading}
    <div class="center"><Spinner size={26} /></div>
  {:else if item}
    {@const target = item}
    <div class="layout">
      <div class="card">
        <h2>Фото</h2>

        {#if photos.length > 0}
          <div class="viewer" style="--photo-ratio: {photoAspect}; --photo-fit: {photoFit};">
            <button
              type="button"
              class="main-photo"
              aria-label="Открыть фото"
              onclick={() => (lightboxOpen = true)}
            >
              {#if mainPhotoUrl}
                <img src={mainPhotoUrl} alt="" />
              {:else}
                <span class="ph"><Icon name="picture" size={28} /></span>
              {/if}
            </button>

            {#if photos.length > 1}
              <button
                type="button"
                class="nav prev"
                aria-label="Предыдущее фото"
                onclick={(event) => {
                  event.stopPropagation();
                  prevPhoto();
                }}>‹</button
              >
              <button
                type="button"
                class="nav next"
                aria-label="Следующее фото"
                onclick={(event) => {
                  event.stopPropagation();
                  nextPhoto();
                }}>›</button
              >
              <span class="counter">{photoIndex + 1}/{photos.length}</span>
            {/if}
          </div>

          {#if photos.length > 1}
            <div class="thumbs">
              {#each photos as photo, index (photo.id)}
                <button
                  type="button"
                  class="thumb"
                  class:active={index === photoIndex}
                  aria-label={`Фото ${index + 1}`}
                  onclick={() => {
                    if (index === photoIndex) {
                      lightboxOpen = true;
                    } else {
                      photoIndex = index;
                    }
                  }}
                >
                  {#if thumbUrl(photo.id)}
                    <img src={thumbUrl(photo.id)} alt="" />
                  {:else}
                    <span class="ph"><Icon name="picture" size={14} /></span>
                  {/if}
                </button>
              {/each}
            </div>
          {/if}
        {:else}
          <p class="hint">Фото не загружены.</p>
        {/if}
      </div>

      <div class="card">
        <div class="tabs">
          <button type="button" class:active={tab === 'main'} onclick={() => (tab = 'main')}>
            Основное
          </button>
          <button type="button" class:active={tab === 'levels'} onclick={() => (tab = 'levels')}>
            Остатки
          </button>
          {#if isSet}
            <button
              type="button"
              class:active={tab === 'composition'}
              onclick={() => (tab = 'composition')}
            >
              Состав
            </button>
          {/if}
        </div>

        {#if tab === 'main'}
          <div class="form">
            {#if itemTypes.length > 0}
              <div class="field">
                <span class="label">Тип позиции</span>
                <span class="value">{typeTitle || '—'}</span>
              </div>
            {/if}

            <div class="field">
              <span class="label">Название</span>
              <span class="value">{target.name}</span>
            </div>

            <div class="row">
              <div class="field">
                <span class="label">Артикул</span>
                <span class="value">{target.article || '—'}</span>
              </div>
              <div class="field">
                <span class="label">Единица измерения</span>
                <span class="value">{target.unit || '—'}</span>
              </div>
            </div>

            {#if groupsEnabled}
              <div class="field">
                <span class="label">Группа</span>
                <span class="value">{target.group_title || 'Без группы'}</span>
              </div>
            {/if}

            <div class="field">
              <span class="label">Описание</span>
              <span class="value desc">{target.description || '—'}</span>
            </div>
          </div>
        {:else if tab === 'levels'}
          <div class="levels">
            <div class="levels-total">
              <span class="label">Общий остаток</span>
              <span class="total-value">{stockQuantityText(totalQuantity, target.unit)}</span>
            </div>

            <div class="levels-scroll">
            <div class="levels-table">
              <div class="levels-head">
                <span class="levels-head-cell">Склад</span>
                <span class="levels-head-cell col-price">Цена</span>
                <span class="levels-head-cell col-stock">Остаток</span>
                <span class="levels-head-cell col-cart">Корзина</span>
              </div>

              {#each levels as level (level.warehouse_sid)}
                {@const levelCartQty = cart.quantityOf(level.warehouse_id, target.name)}
                <div class="level-row" class:current={level.current}>
                  <div class="level-wh">
                    <span class="level-name">
                      {level.warehouse_name}
                      {#if level.current}<span class="tag">текущий</span>{/if}
                    </span>
                  </div>

                  <div class="level-prices col-price">
                    {#if canEdit && priceColumns.length > 0}
                      {#each priceColumns as type (type.id)}
                        {#if (level.price_type_ids ?? []).includes(type.id)}
                          <span class="price-line">
                            <span class="price-type">{type.title}</span>
                            <span class="price">
                              {formatPrice(
                                target.warehouse_prices?.[level.warehouse_sid]?.[String(type.id)] ??
                                  null
                              )}
                            </span>
                          </span>
                        {/if}
                      {/each}
                    {:else if level.current}
                      {#each priceRows(target) as row (row.id)}
                        <span class="price-line">
                          <span class="price-type">{row.title || 'Цена'}</span>
                          <span class="price" class:missing={row.price === null}>
                            {formatPrice(row.price)}
                          </span>
                        </span>
                      {/each}
                    {:else}
                      <span class="price-dash">—</span>
                    {/if}
                  </div>

                  <span class="level-value col-stock">
                    {stockQuantityText(level.quantity, level.unit || target.unit)}
                  </span>

                  <div class="level-cart col-cart">
                    {#if canCreate && target.active !== false}
                      {#if levelCartQty > 0}
                        <span class="add">
                          <button
                            type="button"
                            class="step"
                            aria-label={`Уменьшить: ${level.warehouse_name}`}
                            onclick={() => cart.decreaseAt(level.warehouse_id, target.name)}
                          >
                            −
                          </button>
                          <span class="qty" aria-label={`В корзине: ${level.warehouse_name}`}>
                            {levelCartQty.toLocaleString('ru-RU', { maximumFractionDigits: 3 })}
                          </span>
                          <button
                            type="button"
                            class="step"
                            aria-label={`Увеличить: ${level.warehouse_name}`}
                            onclick={() => cart.increaseAt(level.warehouse_id, target.name)}
                          >
                            +
                          </button>
                        </span>
                      {:else}
                        <Button
                          variant="text"
                          size="sm"
                          title="Добавить в корзину"
                          ariaLabel={`Добавить в корзину: ${level.warehouse_name}`}
                          onclick={() => addLevelToCart(target, level)}
                        >
                          <Icon name="cart" size={16} /> Добавить
                        </Button>
                      {/if}
                    {/if}
                  </div>
                </div>
              {/each}
            </div>
            </div>
          </div>
        {:else}
          <div class="levels">
            {#if composition.length === 0}
              <div class="empty">Состав пуст</div>
            {:else}
              {#each composition as row (row.item_sid)}
                <div class="level-row">
                  <span class="level-name">
                    {row.name}
                    <span class="tag">{typeTitleOf(row.type)}</span>
                  </span>
                  <span class="level-value">{stockQuantityText(row.quantity, row.unit)}</span>
                </div>
              {/each}
            {/if}
          </div>
        {/if}

      </div>
    </div>

    <Modal
      open={lightboxOpen}
      label="Фото"
      wide
      closeButton={false}
      onclose={() => (lightboxOpen = false)}
    >
      <div class="lightbox">
        {#if lightboxUrl}
          <img src={lightboxUrl} alt="" />
        {:else}
          <div class="lb-placeholder"><Icon name="picture" size={40} /></div>
        {/if}

        {#if photos.length > 1}
          <button type="button" class="lb-nav prev" aria-label="Предыдущее фото" onclick={prevPhoto}>
            <Icon name="arrow-left" size={18} />
          </button>
          <button type="button" class="lb-nav next" aria-label="Следующее фото" onclick={nextPhoto}>
            <Icon name="arrow-right" size={18} />
          </button>
          <span class="lb-counter">{photoIndex + 1}/{photos.length}</span>
        {/if}
      </div>
    </Modal>
  {/if}
</section>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-5);
  }

  .head {
    display: flex;
    align-items: flex-start;
    gap: var(--space-3);
  }

  .back {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    cursor: pointer;
    flex: 0 0 auto;
  }

  .back:hover {
    border-color: var(--primary-hover);
    color: var(--primary-hover);
  }

  .head-text {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    min-width: 0;
  }

  h1 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    line-height: 1.4;
    color: var(--text);
    overflow-wrap: anywhere;
  }

  .status {
    display: inline-block;
    vertical-align: middle;
    margin-left: var(--space-2);
    padding: 0 7px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
    line-height: 20px;
  }

  .status.off {
    border: 1px solid var(--danger);
    background: var(--danger-bg);
    color: var(--danger);
  }

  .head-actions {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex: 0 0 auto;
    margin-left: auto;
  }

  .layout {
    display: grid;
    grid-template-columns: minmax(0, 320px) minmax(0, 1fr);
    gap: var(--space-4);
    align-items: start;
  }

  @media (max-width: 860px) {
    .layout {
      grid-template-columns: minmax(0, 1fr);
    }
  }

  .card {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    padding: var(--space-4);
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-md);
    background: var(--surface);
  }

  h2 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: var(--text);
  }

  .viewer {
    position: relative;
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
    overflow: hidden;
  }

  .main-photo {
    display: block;
    width: 100%;
    aspect-ratio: var(--photo-ratio, 1 / 1);
    padding: 0;
    border: none;
    background: var(--fill-tertiary);
    cursor: zoom-in;
  }

  .main-photo img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: var(--photo-fit, contain);
  }

  .ph {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    min-height: 40px;
    color: var(--text-description);
  }

  .nav {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 28px;
    height: 28px;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.45);
    color: #fff;
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
    opacity: 0;
    transition: opacity 0.15s ease;
  }

  .viewer:hover .nav {
    opacity: 1;
  }

  .nav.prev {
    left: 6px;
  }

  .nav.next {
    right: 6px;
  }

  .counter {
    position: absolute;
    right: 6px;
    bottom: 6px;
    padding: 1px 6px;
    border-radius: 999px;
    background: rgba(0, 0, 0, 0.55);
    color: #fff;
    font-size: 11px;
  }

  .thumbs {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: var(--space-2);
  }

  .thumb {
    width: 100%;
    aspect-ratio: 1 / 1;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    overflow: hidden;
    background: var(--fill-tertiary);
    cursor: pointer;
  }

  .thumb.active {
    border-color: var(--primary);
    box-shadow: 0 0 0 1px var(--primary);
  }

  .thumb img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .lightbox {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 200px;
  }

  .lightbox img {
    display: block;
    max-width: 100%;
    max-height: 70vh;
    object-fit: contain;
  }

  .lb-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 320px;
    color: var(--text-description);
  }

  .lb-nav {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    padding: 0;
    border: 1px solid rgba(255, 255, 255, 0.35);
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.5);
    color: #fff;
    cursor: pointer;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
    transition: background-color 0.15s ease;
  }

  .lb-nav:hover {
    background: rgba(0, 0, 0, 0.72);
  }

  .lb-nav.prev {
    left: 12px;
  }

  .lb-nav.next {
    right: 12px;
  }

  .lb-counter {
    position: absolute;
    right: 12px;
    bottom: 10px;
    padding: 2px 8px;
    border-radius: 999px;
    background: rgba(0, 0, 0, 0.55);
    color: #fff;
    font-size: 12px;
  }

  .tabs {
    display: flex;
    gap: var(--space-6);
    border-bottom: 1px solid var(--border-secondary);
  }

  .tabs button {
    padding: 12px 0;
    border: none;
    border-bottom: 2px solid transparent;
    background: none;
    color: var(--text);
    font-size: 14px;
    cursor: pointer;
    transition: color 0.2s ease;
  }

  .tabs button:hover {
    color: var(--primary);
  }

  .tabs button.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
  }

  .form {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: var(--space-3);
  }

  .field {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    min-width: 0;
  }

  .label {
    font-size: 14px;
    color: var(--text-description);
  }

  .value {
    font-size: 14px;
    color: var(--text);
    overflow-wrap: anywhere;
  }

  .value.desc {
    white-space: pre-wrap;
    line-height: 1.5;
  }

  .levels {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .levels-total {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-3);
    padding: 10px 12px;
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
    background: var(--fill-tertiary);
  }

  .total-value {
    font-size: 16px;
    font-weight: 600;
    color: var(--text);
  }

  .level-row {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    padding: 8px 12px;
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
  }

  .level-row.current {
    border-color: var(--primary);
    background: var(--focus-ring);
  }

  .level-name {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    flex: 1 1 auto;
    min-width: 0;
    font-size: 14px;
    color: var(--text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .level-value {
    flex: 0 0 auto;
    font-size: 14px;
    font-weight: 500;
    color: var(--text);
    white-space: nowrap;
  }

  .levels-scroll {
    overflow-x: auto;
  }

  .levels-table {
    min-width: 640px;
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-md);
    overflow: hidden;
  }

  .levels-head {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 220px) 120px 150px;
    align-items: stretch;
    gap: 0;
    background: var(--fill-tertiary);
    border-bottom: 1px solid var(--border-secondary);
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
  }

  .levels-head-cell {
    position: relative;
    padding: 12px var(--space-4);
  }

  .levels-head-cell:not(:last-child)::after {
    content: '';
    position: absolute;
    top: 50%;
    right: 0;
    width: 1px;
    height: 1.6em;
    background: var(--border-secondary);
    transform: translateY(-50%);
  }

  .levels-head .col-price,
  .levels-head .col-stock,
  .levels-head .col-cart {
    text-align: right;
  }

  .levels-table .level-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 220px) 120px 150px;
    align-items: center;
    gap: 0;
    padding: 0;
    border: none;
    border-bottom: 1px solid var(--border-secondary);
    border-radius: 0;
  }

  .levels-table .level-row:last-child {
    border-bottom: none;
  }

  .levels-table .level-row > * {
    padding: 12px var(--space-4);
    min-width: 0;
  }

  .levels-table .col-price,
  .levels-table .col-stock {
    text-align: right;
  }

  .level-wh {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .level-cart {
    display: flex;
    justify-content: flex-end;
  }

  .level-prices {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .price-line {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-2);
    font-size: 12px;
    line-height: 1.35;
    min-width: 0;
  }

  .price-type {
    color: var(--text-description);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .price-dash {
    font-size: 12px;
    color: var(--text-description);
  }

  .tag {
    padding: 0 7px;
    border: 1px solid var(--border);
    border-radius: 4px;
    background: var(--surface);
    font-size: 12px;
    line-height: 20px;
    color: var(--text-description);
  }

  .price {
    font-weight: 600;
    white-space: nowrap;
  }

  .price.missing {
    color: var(--muted);
    font-weight: 400;
  }

  .empty {
    padding: var(--space-5);
    text-align: center;
    font-size: 14px;
    color: var(--text-description);
  }

  .add {
    display: inline-flex;
    align-items: center;
    gap: 2px;
  }

  .qty {
    min-width: 26px;
    text-align: center;
    font-weight: 500;
    white-space: nowrap;
  }

  .step {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: none;
    background: none;
    color: var(--text);
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
  }

  .step:hover {
    color: var(--primary);
  }

  .hint {
    margin: 0;
    font-size: 13px;
    color: var(--text-description);
  }

  .alert {
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    padding: 8px 12px;
    border: 1px solid var(--error-border);
    border-radius: var(--radius-md);
    background: var(--danger-bg);
    color: var(--text);
    font-size: 14px;
  }

  .alert-icon {
    display: inline-flex;
    flex: 0 0 auto;
    margin-top: 2px;
    color: var(--danger);
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }
</style>
