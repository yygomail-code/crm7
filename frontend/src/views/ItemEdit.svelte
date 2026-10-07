<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    createStockItem,
    deleteItemPhoto,
    getStockLevel,
    invalidateItemPhoto,
    listWarehouses,
    loadItemPhotoUrl,
    updateStockItem,
    uploadItemPhoto
  } from '../lib/api/stocks';
  import { listItemGroups } from '../lib/api/groups';
  import { listPriceTypes } from '../lib/api/prices';
  import type { ItemGroup, PriceType, StockPhoto, StockWarehouse } from '../lib/api/types';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Input from '../lib/components/ui/Input.svelte';
  import PhotoGallery from '../lib/components/stocks/PhotoGallery.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { router } from '../lib/router.svelte';

  interface Props {
    id: number;
  }

  let { id }: Props = $props();

  const MAX_PHOTOS = 10;
  const isNew = $derived(id <= 0);

  let loading = $state(true);
  let saving = $state(false);
  let error = $state('');
  let message = $state('');

  let name = $state('');
  let unit = $state('');
  let quantity = $state('0');
  let description = $state('');
  let groupId = $state(0);
  let prices = $state<Record<string, string>>({});

  let warehouses = $state<StockWarehouse[]>([]);
  let warehouseId = $state(0);
  let warehouseName = $state('');
  let groups = $state<ItemGroup[]>([]);
  let priceTypes = $state<PriceType[]>([]);

  let canEdit = $state(false);
  let canManagePhotos = $state(false);
  let pricesEnabled = $state(false);
  let groupsEnabled = $state(false);

  let photos = $state<StockPhoto[]>([]);
  let photoUrls = $state<Record<number, string | null>>({});
  let photoBusy = $state(false);
  let photoError = $state('');

  onMount(() => {
    void load();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      if (isNew) {
        const data = await listWarehouses();
        warehouses = data.items;
        warehouseId = data.items[0]?.id ?? 0;
        canEdit = data.can_edit;
        canManagePhotos = data.can_edit || data.can_import;
        pricesEnabled = appSettings.pricesEnabled;
        groupsEnabled = appSettings.groupsEnabled;
      } else {
        const data = await getStockLevel(id);
        const item = data.item;

        name = item.name;
        unit = item.unit;
        quantity = String(item.quantity);
        description = item.description ?? '';
        groupId = item.group_id ?? 0;
        warehouseId = item.warehouse_id;
        warehouseName = item.warehouse_name;
        canEdit = data.can_edit;
        canManagePhotos = data.can_manage_photos;
        pricesEnabled = data.prices_enabled;
        groupsEnabled = data.groups_enabled;
        photos = item.photos ?? [];
        prices = Object.fromEntries(
          Object.entries(item.prices ?? {}).map(([typeId, value]) => [typeId, String(value)])
        );
        void loadPhotoUrls();
      }

      if (pricesEnabled) {
        priceTypes = (await listPriceTypes().catch(() => ({ items: [] }))).items;
      }

      if (groupsEnabled) {
        groups = (await listItemGroups().catch(() => ({ items: [] }))).items;
      }

      for (const type of priceTypes) {
        if (prices[String(type.id)] === undefined) {
          prices[String(type.id)] = '';
        }
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить позицию';
    } finally {
      loading = false;
    }
  }

  async function loadPhotoUrls(): Promise<void> {
    for (const photo of photos) {
      if (photoUrls[photo.id] === undefined) {
        photoUrls[photo.id] = await loadItemPhotoUrl(photo.id);
      }
    }
  }

  async function uploadPhotoFile(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    input.value = '';

    if (file === null || id <= 0) {
      return;
    }

    photoBusy = true;
    photoError = '';

    try {
      const result = await uploadItemPhoto(id, file);
      photos = result.photos;
      await loadPhotoUrls();
    } catch (cause) {
      photoError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить фото';
    } finally {
      photoBusy = false;
    }
  }

  async function removePhoto(photoId: number): Promise<void> {
    photoBusy = true;
    photoError = '';

    try {
      const result = await deleteItemPhoto(photoId);
      photos = result.photos;
      invalidateItemPhoto(photoId);
      delete photoUrls[photoId];
    } catch (cause) {
      photoError = cause instanceof ApiError ? cause.message : 'Не удалось удалить фото';
    } finally {
      photoBusy = false;
    }
  }

  async function save(): Promise<void> {
    error = '';
    message = '';

    const trimmedName = name.trim();

    if (trimmedName === '') {
      error = 'Укажите название позиции';
      return;
    }

    const qty = Number(quantity.replace(',', '.'));

    if (!Number.isFinite(qty) || qty < 0) {
      error = 'Укажите корректный остаток (не меньше нуля)';
      return;
    }

    const pricePayload: Record<string, number | null> = {};

    if (pricesEnabled) {
      for (const type of priceTypes) {
        const raw = (prices[String(type.id)] ?? '').trim();

        if (raw === '') {
          pricePayload[String(type.id)] = null;
          continue;
        }

        const value = Number(raw.replace(',', '.'));

        if (!Number.isFinite(value) || value < 0) {
          error = `Некорректная цена «${type.title}»`;
          return;
        }

        pricePayload[String(type.id)] = value;
      }
    }

    if (isNew && warehouseId <= 0) {
      error = 'Выберите склад';
      return;
    }

    saving = true;

    try {
      const payload = {
        name: trimmedName,
        unit: unit.trim(),
        quantity: qty,
        description: description.trim(),
        ...(pricesEnabled && priceTypes.length > 0 ? { prices: pricePayload } : {}),
        ...(groupsEnabled ? { group_id: groupId > 0 ? groupId : null } : {})
      };

      if (isNew) {
        const result = await createStockItem(warehouseId, payload);
        router.navigate(`/stocks/items/${result.item.id}/edit`);
        return;
      }

      const result = await updateStockItem(id, payload);
      name = result.item.name;
      prices = Object.fromEntries(
        Object.entries(result.item.prices ?? {}).map(([typeId, value]) => [typeId, String(value)])
      );
      message = 'Изменения сохранены';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить позицию';
    } finally {
      saving = false;
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
      <h1>{isNew ? 'Новая позиция' : name || 'Редактирование позиции'}</h1>
      {#if !isNew && warehouseName}
        <p class="head-subtitle">Склад: {warehouseName}</p>
      {/if}
    </div>
  </div>

  {#if error}
    <div class="alert">
      <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
      <span>{error}</span>
    </div>
  {/if}
  {#if message}
    <div class="notice">
      <span class="notice-icon"><Icon name="check-circle" size={16} /></span>
      <span>{message}</span>
    </div>
  {/if}

  {#if loading}
    <div class="center"><Spinner size={26} /></div>
  {:else}
    <div class="layout">
      <div class="card">
        <h2>Фото</h2>

        {#if photos.length > 0}
          <div class="viewer">
            <PhotoGallery photos={photos} variant="tile" />
          </div>
        {/if}

        <div class="photo-grid">
          {#each photos as photo (photo.id)}
            <div class="photo-item">
              {#if photoUrls[photo.id]}
                <img src={photoUrls[photo.id]} alt="" />
              {/if}
              {#if canManagePhotos}
                <button
                  type="button"
                  class="photo-remove"
                  title="Удалить фото"
                  aria-label="Удалить фото"
                  disabled={photoBusy}
                  onclick={() => void removePhoto(photo.id)}
                >
                  ×
                </button>
              {/if}
            </div>
          {/each}

          {#if canManagePhotos && !isNew && photos.length < MAX_PHOTOS}
            <label class="photo-add" class:busy={photoBusy}>
              <input
                type="file"
                accept="image/jpeg,image/png,image/webp"
                disabled={photoBusy}
                onchange={uploadPhotoFile}
              />
              <Icon name="camera" size={20} />
              <span>Добавить</span>
            </label>
          {/if}
        </div>

        {#if isNew}
          <p class="hint">Фото можно добавить после сохранения позиции.</p>
        {:else}
          <p class="hint">До {MAX_PHOTOS} фото. Форматы: jpg, png, webp. Размер до 5 МБ.</p>
        {/if}

        {#if photoError}
          <div class="alert">
            <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
            <span>{photoError}</span>
          </div>
        {/if}
      </div>

      <div class="card">
        <h2>Параметры</h2>

        {#if !canEdit}
          <div class="alert">
            <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
            <span>Недостаточно прав для изменения позиции</span>
          </div>
        {/if}

        <div class="form">
          {#if isNew && warehouses.length > 0}
            <label class="field">
              <span class="label">Склад</span>
              <select bind:value={warehouseId}>
                {#each warehouses as warehouse (warehouse.id)}
                  <option value={warehouse.id}>{warehouse.name}</option>
                {/each}
              </select>
            </label>
          {/if}

          <Input label="Название" bind:value={name} placeholder="Например: Смартфон Pixel 9" />

          <div class="row">
            <Input label="Единица измерения" bind:value={unit} placeholder="шт" />
            <Input label="Остаток на складе" type="number" bind:value={quantity} />
          </div>

          {#if groupsEnabled && groups.length > 0}
            <label class="field">
              <span class="label">Группа</span>
              <select bind:value={groupId}>
                <option value={0}>Без группы</option>
                {#each groups as group (group.id)}
                  <option value={group.id}>{group.title}</option>
                {/each}
              </select>
            </label>
          {/if}

          <label class="field">
            <span class="label">Описание</span>
            <textarea
              bind:value={description}
              rows="3"
              placeholder="Характеристики, цвет, память — покажется в подсказке при наведении"
            ></textarea>
          </label>

          {#if pricesEnabled && priceTypes.length > 0}
            <div class="prices">
              <span class="label">Цены</span>
              <div class="row">
                {#each priceTypes as type (type.id)}
                  <Input label={type.title} bind:value={prices[String(type.id)]} placeholder="0,00" />
                {/each}
              </div>
            </div>
          {/if}
        </div>

        <div class="actions">
          <Button variant="ghost" onclick={() => router.navigate('/stocks')}>Отмена</Button>
          <Button loading={saving} disabled={!canEdit} onclick={() => void save()}>
            {isNew ? 'Создать позицию' : 'Сохранить'}
          </Button>
        </div>
      </div>
    </div>
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

  .head-subtitle {
    margin: 0;
    font-size: 14px;
    color: var(--text-description);
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
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
    overflow: hidden;
  }

  .photo-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(72px, 1fr));
    gap: var(--space-2);
  }

  .photo-item {
    position: relative;
    aspect-ratio: 1;
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
    overflow: hidden;
    background: var(--fill-tertiary);
  }

  .photo-item img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .photo-remove {
    position: absolute;
    top: 3px;
    right: 3px;
    width: 20px;
    height: 20px;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.55);
    color: #fff;
    font-size: 14px;
    line-height: 1;
    cursor: pointer;
  }

  .photo-remove:disabled {
    opacity: 0.5;
    cursor: default;
  }

  .photo-add {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    aspect-ratio: 1;
    border: 1px dashed var(--border);
    border-radius: var(--radius-sm);
    color: var(--muted);
    font-size: 12px;
    cursor: pointer;
  }

  .photo-add:hover {
    border-color: var(--primary);
    color: var(--primary);
  }

  .photo-add.busy {
    opacity: 0.6;
    pointer-events: none;
  }

  .photo-add input {
    display: none;
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
  }

  .label {
    font-size: 14px;
    color: var(--text-description);
  }

  .field select,
  .field textarea {
    width: 100%;
    padding: 0 11px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    font-size: 14px;
    color: var(--text);
    outline: none;
  }

  .field select {
    height: 32px;
  }

  .field textarea {
    padding: 6px 11px;
    resize: vertical;
  }

  .field select:focus,
  .field textarea:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(5, 145, 255, 0.1);
  }

  .prices {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
  }

  .actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-2);
    padding-top: var(--space-3);
    border-top: 1px solid var(--border-secondary);
  }

  .hint {
    margin: 0;
    font-size: 13px;
    color: var(--text-description);
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }

  .alert,
  .notice {
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    color: var(--text);
    font-size: 14px;
  }

  .alert {
    border-color: var(--error-border);
    background: var(--danger-bg);
  }

  .notice {
    border-color: var(--success-border);
    background: var(--success-bg);
  }

  .alert-icon {
    display: inline-flex;
    flex: 0 0 auto;
    margin-top: 2px;
    color: var(--danger);
  }

  .notice-icon {
    display: inline-flex;
    flex: 0 0 auto;
    margin-top: 2px;
    color: var(--success);
  }
</style>
