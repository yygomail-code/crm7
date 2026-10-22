<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    activateStockItem,
    createStockItem,
    deactivateStockItem,
    deleteItemPhoto,
    deleteStockItem,
    getStockLevel,
    invalidateItemPhoto,
    listItemTypes,
    listWarehouses,
    loadItemPhotoUrl,
    searchNomenclature,
    updateStockItem,
    uploadItemPhoto
  } from '../lib/api/stocks';
  import { listItemGroups } from '../lib/api/groups';
  import { listPriceTypes } from '../lib/api/prices';
  import type {
    ItemGroup,
    ItemType,
    NomenclatureItem,
    PriceType,
    StockCompositionItem,
    StockLevel,
    StockLevelEntry,
    StockPhoto,
    StockWarehouse
  } from '../lib/api/types';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Input from '../lib/components/ui/Input.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import PhotoGallery from '../lib/components/stocks/PhotoGallery.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { formatDate, stockQuantityText } from '../lib/format';
  import { router } from '../lib/router.svelte';

  interface Props {
    id: number;
  }

  let { id }: Props = $props();

  const MAX_PHOTOS = 10;
  const UNITS = [
    'шт',
    'упак',
    'компл',
    'набор',
    'пар',
    'кг',
    'г',
    'т',
    'л',
    'мл',
    'м',
    'см',
    'мм',
    'м²',
    'м³',
    'рул',
    'лист',
    'меш',
    'пач',
    'кор'
  ];
  const isNew = $derived(id <= 0);
  const photoAspect = $derived(appSettings.photoAspect);
  const photoFit = $derived(appSettings.photoFit);

  let loading = $state(true);
  let saving = $state(false);
  let error = $state('');
  let message = $state('');

  let name = $state('');
  let unit = $state('шт');
  let article = $state('');
  let quantity = $state('0');
  let description = $state('');
  let groupId = $state(0);
  let warehousePrices = $state<Record<string, Record<string, string>>>({});

  let warehouses = $state<StockWarehouse[]>([]);
  let warehouseId = $state(0);
  let groups = $state<ItemGroup[]>([]);
  let priceTypes = $state<PriceType[]>([]);

  let tab = $state<'main' | 'levels' | 'prices' | 'composition'>('main');
  let levels = $state<StockLevelEntry[]>([]);
  let levelEdits = $state<Record<string, string>>({});

  let itemType = $state('product');
  let itemTypes = $state<ItemType[]>([]);
  let composition = $state<StockCompositionItem[]>([]);
  let compositionEdits = $state<Record<string, string>>({});

  let pickerOpen = $state(false);
  let pickerQuery = $state('');
  let pickerResults = $state<NomenclatureItem[]>([]);
  let pickerLoading = $state(false);
  let pickerError = $state('');

  const TYPE_TITLES: Record<string, string> = { product: 'Товар', service: 'Услуга', set: 'Набор' };

  const isSet = $derived(itemType === 'set');

  function typeTitleOf(code: string): string {
    return itemTypes.find((type) => type.code === code)?.title ?? TYPE_TITLES[code] ?? code;
  }

  function buildWarehousePrices(item: StockLevel): Record<string, Record<string, string>> {
    const matrix = item.warehouse_prices ?? {};
    const legacy = item.prices ?? {};
    const result: Record<string, Record<string, string>> = {};

    for (const level of levels) {
      const sid = level.warehouse_sid;
      const source: Record<string, number> = {
        ...(level.current ? legacy : {}),
        ...(matrix[sid] ?? {})
      };
      const row: Record<string, string> = {};

      for (const type of priceTypes) {
        const value = source[String(type.id)];
        row[String(type.id)] = value === undefined || value === null ? '' : String(value);
      }

      result[sid] = row;
    }

    return result;
  }

  const priceColumns = $derived(
    priceTypes.filter((type) =>
      levels.some((level) => (level.price_type_ids ?? []).includes(type.id))
    )
  );

  const currentLevel = $derived(levels.find((level) => level.current) ?? null);
  const totalQuantity = $derived(
    levels.reduce((sum, level) => {
      const value = Number(String(levelEdits[level.warehouse_sid] ?? level.quantity).replace(',', '.'));

      return sum + (Number.isFinite(value) ? value : 0);
    }, 0)
  );

  $effect(() => {
    if (!isSet && tab === 'composition') {
      tab = 'main';
    }
  });

  $effect(() => {
    const query = pickerQuery;
    const timer = setTimeout(() => void runPickerSearch(query), 350);

    return () => clearTimeout(timer);
  });

  let canEdit = $state(false);
  let canManagePhotos = $state(false);
  let canDeactivate = $state(false);
  let canDelete = $state(false);
  let deactivateBlocked = $state<string | null>(null);
  let deleteBlocked = $state<string | null>(null);
  let active = $state(true);
  let statusBusy = $state(false);
  let pricesEnabled = $state(false);
  let groupsEnabled = $state(false);

  let photos = $state<StockPhoto[]>([]);
  let photoUrls = $state<Record<number, string | null>>({});
  let photoBusy = $state(false);
  let photoError = $state('');

  let lightboxOpen = $state(false);
  let photoIndex = $state(0);
  let maxUrls = $state<Record<number, string | null>>({});

  const lightboxUrl = $derived.by(() => {
    const photo = photos[photoIndex];

    return photo === undefined ? null : (maxUrls[photo.id] ?? photoUrls[photo.id] ?? null);
  });

  $effect(() => {
    if (!lightboxOpen) {
      return;
    }

    for (const photo of photos) {
      if (maxUrls[photo.id] === undefined) {
        void loadItemPhotoUrl(photo.id, 'max').then((url) => {
          maxUrls[photo.id] = url;
        });
      }
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

  const deactivateBlockReason = $derived(
    deactivateBlocked === 'in_sets'
      ? 'Позиция входит в состав наборов — сначала уберите её из состава.'
      : ''
  );
  const deleteBlockReason = $derived(
    deleteBlocked === 'in_sets'
      ? 'Позиция входит в состав наборов — сначала уберите её из состава.'
      : deleteBlocked === 'in_requests'
        ? 'Позиция использовалась в заявках — удалить нельзя, доступна деактивация.'
        : ''
  );

  onMount(() => {
    void load();
  });

  function goBack(): void {
    router.navigate(isNew ? '/stocks' : `/stocks/items/${id}`);
  }

  async function load(): Promise<void> {
    loading = true;
    error = '';
    let loadedItem: StockLevel | null = null;

    try {
      if (isNew) {
        const data = await listWarehouses();
        warehouses = data.items;
        warehouseId = data.items[0]?.id ?? 0;
        canEdit = data.can_edit;
        canManagePhotos = data.can_edit || data.can_import;
        pricesEnabled = appSettings.pricesEnabled;
        groupsEnabled = appSettings.groupsEnabled;
        itemTypes = (await listItemTypes().catch(() => ({ items: [] }))).items;
      } else {
        const data = await getStockLevel(id);
        const item = data.item;

        name = item.name;
        unit = item.unit;
        article = item.article ?? '';
        quantity = String(item.quantity);
        description = item.description ?? '';
        groupId = item.group_id ?? 0;
        warehouseId = item.warehouse_id;
        canEdit = data.can_edit;
        canManagePhotos = data.can_manage_photos;
        canDeactivate = data.can_deactivate;
        canDelete = data.can_delete;
        deactivateBlocked = data.deactivate_blocked;
        deleteBlocked = data.delete_blocked;
        active = item.active ?? true;
        pricesEnabled = data.prices_enabled;
        groupsEnabled = data.groups_enabled;
        itemType = item.type ?? 'product';
        itemTypes = data.item_types ?? [];
        composition = item.composition ?? [];
        compositionEdits = Object.fromEntries(
          composition.map((row) => [row.item_sid, String(row.quantity)])
        );
        photos = item.photos ?? [];
        levels = item.levels ?? [];
        levelEdits = Object.fromEntries(levels.map((level) => [level.warehouse_sid, String(level.quantity)]));
        void loadPhotoUrls();
        loadedItem = item;
      }

      if (pricesEnabled) {
        priceTypes = (await listPriceTypes().catch(() => ({ items: [] }))).items;
      }

      if (groupsEnabled) {
        groups = (await listItemGroups().catch(() => ({ items: [] }))).items;
      }

      if (loadedItem !== null) {
        warehousePrices = buildWarehousePrices(loadedItem);
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
        photoUrls[photo.id] = await loadItemPhotoUrl(photo.id, 'preview');
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

  function openPicker(): void {
    pickerOpen = true;
    pickerQuery = '';
    pickerResults = [];
    pickerError = '';
  }

  async function runPickerSearch(query: string): Promise<void> {
    const trimmed = query.trim();

    if (!pickerOpen || trimmed === '') {
      pickerResults = [];
      return;
    }

    pickerLoading = true;
    pickerError = '';

    try {
      pickerResults = (await searchNomenclature(trimmed)).items;
    } catch (cause) {
      pickerError = cause instanceof ApiError ? cause.message : 'Не удалось выполнить поиск';
    } finally {
      pickerLoading = false;
    }
  }

  function addComponent(item: NomenclatureItem): void {
    if (composition.some((row) => row.item_sid === item.sid)) {
      return;
    }

    composition = [
      ...composition,
      {
        item_sid: item.sid,
        name: item.name,
        unit: item.unit,
        type: item.type,
        article: item.article,
        quantity: 1
      }
    ];
    compositionEdits = { ...compositionEdits, [item.sid]: '1' };
  }

  function removeComponent(sid: string): void {
    composition = composition.filter((row) => row.item_sid !== sid);
    const next = { ...compositionEdits };
    delete next[sid];
    compositionEdits = next;
  }

  async function toggleActive(): Promise<void> {
    if (isNew || statusBusy) {
      return;
    }

    statusBusy = true;
    error = '';
    message = '';

    try {
      const result = active ? await deactivateStockItem(id) : await activateStockItem(id);
      active = result.active;
      message = active ? 'Позиция активирована' : 'Позиция деактивирована';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось изменить статус позиции';
    } finally {
      statusBusy = false;
    }
  }

  async function deleteItem(): Promise<void> {
    if (isNew || statusBusy) {
      return;
    }

    if (!confirm(`Удалить позицию «${name || 'без названия'}»? Действие необратимо.`)) {
      return;
    }

    statusBusy = true;
    error = '';
    message = '';

    try {
      await deleteStockItem(id);
      router.navigate('/stocks');
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось удалить позицию';
      statusBusy = false;
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

    const rawQuantity = currentLevel !== null ? levelEdits[currentLevel.warehouse_sid] : quantity;
    const qty = Number(String(rawQuantity ?? '').replace(',', '.'));

    if (!Number.isFinite(qty) || qty < 0) {
      error = 'Укажите корректный остаток (не меньше нуля)';
      return;
    }

    const pricePayload: Record<string, Record<string, number | null>> = {};

    if (pricesEnabled) {
      for (const level of levels) {
        const row = warehousePrices[level.warehouse_sid] ?? {};
        const usedTypes = level.price_type_ids ?? [];
        const warehouseRow: Record<string, number | null> = {};

        for (const type of priceTypes) {
          if (!usedTypes.includes(type.id)) {
            continue;
          }

          const raw = (row[String(type.id)] ?? '').trim();

          if (raw === '') {
            warehouseRow[String(type.id)] = null;
            continue;
          }

          const value = Number(raw.replace(',', '.'));

          if (!Number.isFinite(value) || value < 0) {
            error = `Некорректная цена «${type.title}» для склада «${level.warehouse_name}»`;
            return;
          }

          warehouseRow[String(type.id)] = value;
        }

        pricePayload[level.warehouse_sid] = warehouseRow;
      }
    }

    if (isNew && warehouseId <= 0) {
      error = 'Нет доступных складов для создания позиции';
      return;
    }

    const levelsPayload: { warehouse_sid: string; quantity: number }[] = [];

    for (const level of levels) {
      if (level.current) {
        continue;
      }

      const value = Number(String(levelEdits[level.warehouse_sid] ?? '').replace(',', '.'));

      if (!Number.isFinite(value) || value < 0) {
        error = `Некорректный остаток на складе «${level.warehouse_name}»`;
        return;
      }

      levelsPayload.push({ warehouse_sid: level.warehouse_sid, quantity: value });
    }

    const compositionPayload: { item_sid: string; quantity: number }[] = [];

    if (isSet) {
      for (const row of composition) {
        const value = Number(String(compositionEdits[row.item_sid] ?? row.quantity).replace(',', '.'));

        if (!Number.isFinite(value) || value <= 0) {
          error = `Некорректное количество в составе: «${row.name}»`;
          return;
        }

        compositionPayload.push({ item_sid: row.item_sid, quantity: value });
      }
    }

    saving = true;

    try {
      const payload = {
        name: trimmedName,
        unit: unit.trim(),
        article: article.trim(),
        type: itemType,
        quantity: qty,
        description: description.trim(),
        ...(pricesEnabled && priceColumns.length > 0 ? { warehouse_prices: pricePayload } : {}),
        ...(groupsEnabled ? { group_id: groupId > 0 ? groupId : null } : {}),
        ...(levelsPayload.length > 0 ? { levels: levelsPayload } : {}),
        ...(isSet ? { composition: compositionPayload } : {})
      };

      if (isNew) {
        const result = await createStockItem(warehouseId, payload);
        router.navigate(`/stocks/items/${result.item.id}/edit`);
        return;
      }

      const result = await updateStockItem(id, payload);
      name = result.item.name;
      levels = levels.map((level) => ({
        ...level,
        quantity: Number(String(levelEdits[level.warehouse_sid] ?? level.quantity).replace(',', '.'))
      }));

      if (result.item.warehouse_prices) {
        warehousePrices = buildWarehousePrices(result.item);
      }

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
      title="Назад"
      aria-label="Назад"
      onclick={goBack}
    >
      <Icon name="arrow-left" size={16} />
    </button>
    <div class="head-text">
      <h1>
        {isNew ? 'Новая позиция' : name || 'Редактирование позиции'}
        {#if !isNew && !active}
          <span class="status off">деактивирована</span>
        {/if}
      </h1>
    </div>

    {#if !isNew && (canDeactivate || canDelete)}
      <div class="head-actions">
        {#if canDeactivate}
          <Button
            variant="ghost"
            loading={statusBusy}
            disabled={deactivateBlocked !== null}
            title={deactivateBlockReason}
            onclick={() => void toggleActive()}
          >
            {active ? 'Деактивировать' : 'Активировать'}
          </Button>
        {/if}
        {#if canDelete}
          <Button
            variant="danger"
            loading={statusBusy}
            disabled={deleteBlocked !== null}
            title={deleteBlockReason}
            onclick={() => void deleteItem()}
          >
            Удалить
          </Button>
        {/if}
      </div>
    {/if}
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
            <PhotoGallery
              photos={photos}
              variant="tile"
              bind:index={photoIndex}
              onopen={() => (lightboxOpen = true)}
            />
          </div>
        {/if}

        <div class="photo-grid" style="--photo-ratio: {photoAspect}; --photo-fit: {photoFit};">
          {#each photos as photo, index (photo.id)}
            <div class="photo-item">
              <button
                type="button"
                class="photo-thumb"
                aria-label={`Открыть фото ${index + 1}`}
                onclick={() => {
                  photoIndex = index;
                  lightboxOpen = true;
                }}
              >
                {#if photoUrls[photo.id]}
                  <img src={photoUrls[photo.id]} alt="" />
                {/if}
              </button>
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
        <div class="tabs">
          <button type="button" class:active={tab === 'main'} onclick={() => (tab = 'main')}>
            Основное
          </button>
          {#if !isNew || warehouses.length > 0}
            <button type="button" class:active={tab === 'levels'} onclick={() => (tab = 'levels')}>
              Остатки
            </button>
          {/if}
          {#if !isNew && pricesEnabled && priceColumns.length > 0}
            <button
              type="button"
              class:active={tab === 'prices'}
              onclick={() => (tab = 'prices')}
            >
              Цены
            </button>
          {/if}
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

        {#if isNew}
          <p class="hint">Позиция создаётся деактивированной — после сохранения активируйте её.</p>
        {/if}

        {#if !canEdit}
          <div class="alert">
            <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
            <span>Недостаточно прав для изменения позиции</span>
          </div>
        {/if}

        {#if tab === 'main'}
        <div class="form">
          {#if itemTypes.length > 0}
            <label class="field">
              <span class="label">Тип позиции</span>
              <select bind:value={itemType} disabled={!canEdit}>
                {#if itemType && !itemTypes.some((type) => type.code === itemType)}
                  <option value={itemType}>{typeTitleOf(itemType)}</option>
                {/if}
                {#each itemTypes as type (type.code)}
                  <option value={type.code}>{type.title}</option>
                {/each}
              </select>
            </label>
          {/if}

          <Input label="Название" bind:value={name} placeholder="Например: Смартфон Pixel 9" disabled={!canEdit} />

          <div class="row">
            <Input label="Артикул" bind:value={article} placeholder="Код / SKU" disabled={!canEdit} />
            <label class="field">
              <span class="label">Единица измерения</span>
              <select bind:value={unit} disabled={!canEdit}>
                {#if unit && !UNITS.includes(unit)}
                  <option value={unit}>{unit}</option>
                {/if}
                {#each UNITS as option (option)}
                  <option value={option}>{option}</option>
                {/each}
              </select>
            </label>
          </div>

          {#if groupsEnabled && groups.length > 0}
            <label class="field">
              <span class="label">Группа</span>
              <select bind:value={groupId} disabled={!canEdit}>
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
              disabled={!canEdit}
              placeholder="Характеристики, цвет, память — покажется в подсказке при наведении"
            ></textarea>
          </label>

        </div>
        {:else if tab === 'levels'}
          {#if isNew}
            <div class="levels">
              <div class="level-row">
                <span class="level-name">{warehouses[0]?.name ?? 'Склад'}</span>
                <div class="level-qty">
                  <Input type="number" bind:value={quantity} disabled={!canEdit} />
                </div>
                {#if unit}<span class="level-unit">{unit}</span>{/if}
              </div>
              <p class="hint">Позиция создаётся на этом складе. Остальные склады — после сохранения.</p>
            </div>
          {:else}
            <div class="levels">
              <div class="levels-total">
                <span class="label">Общий остаток</span>
                <span class="total-value">{stockQuantityText(totalQuantity, unit)}</span>
              </div>

              <p class="hint">Позиция одна для всех складов — меняется только количество.</p>

              {#each levels as level (level.warehouse_sid)}
                <div class="level-row" class:current={level.current}>
                  <span class="level-name">
                    {level.warehouse_name}
                    {#if level.current}<span class="tag">текущий</span>{/if}
                  </span>
                  <div class="level-qty">
                    <Input type="number" bind:value={levelEdits[level.warehouse_sid]} disabled={!canEdit} />
                  </div>
                  {#if level.unit}<span class="level-unit">{level.unit}</span>{/if}
                  <span class="level-date">{level.actual_date ? formatDate(level.actual_date) : '—'}</span>
                </div>
              {/each}
            </div>
          {/if}
        {:else if tab === 'prices'}
          <div class="price-table">
            <p class="hint">
              Цены по складам. Для склада доступны только отмеченные в его настройках типы цен;
              для остальных — прочерк.
            </p>

            <div class="pt-scroll">
              <table>
                <thead>
                  <tr>
                    <th class="pt-wh">Склад</th>
                    {#each priceColumns as type (type.id)}
                      <th>{type.title}</th>
                    {/each}
                  </tr>
                </thead>
                <tbody>
                  {#each levels as level (level.warehouse_sid)}
                    <tr class:current={level.current}>
                      <td class="pt-wh">
                        <span class="pt-wh-name">{level.warehouse_name}</span>
                        {#if level.current}<span class="tag">текущий</span>{/if}
                      </td>
                      {#each priceColumns as type (type.id)}
                        <td>
                          {#if (level.price_type_ids ?? []).includes(type.id)}
                            <input
                              type="text"
                              inputmode="decimal"
                              placeholder="0,00"
                              disabled={!canEdit}
                              bind:value={warehousePrices[level.warehouse_sid][String(type.id)]}
                            />
                          {:else}
                            <span class="pt-dash">—</span>
                          {/if}
                        </td>
                      {/each}
                    </tr>
                  {/each}
                </tbody>
              </table>
            </div>
          </div>
        {:else}
          <div class="levels">
            {#if composition.length === 0}
              <div class="empty">Состав пуст — добавьте позиции</div>
            {:else}
              {#each composition as row (row.item_sid)}
                <div class="level-row">
                  <span class="level-name">
                    {row.name}
                    <span class="tag">{typeTitleOf(row.type)}</span>
                  </span>
                  <div class="level-qty">
                    <Input
                      type="number"
                      bind:value={compositionEdits[row.item_sid]}
                      disabled={!canEdit}
                    />
                  </div>
                  {#if row.unit}<span class="level-unit">{row.unit}</span>{/if}
                  {#if canEdit}
                    <button
                      type="button"
                      class="row-remove"
                      title="Убрать из состава"
                      aria-label="Убрать из состава"
                      onclick={() => removeComponent(row.item_sid)}
                    >
                      <Icon name="close" size={14} />
                    </button>
                  {/if}
                </div>
              {/each}
            {/if}

            {#if canEdit}
              <div class="comp-add">
                <Button variant="ghost" onclick={openPicker}>Добавить позицию</Button>
              </div>
            {/if}
          </div>
        {/if}

        <div class="actions">
          <Button variant="ghost" onclick={goBack}>Отмена</Button>
          <Button loading={saving} disabled={!canEdit} onclick={() => void save()}>
            {isNew ? 'Создать позицию' : 'Сохранить'}
          </Button>
        </div>
      </div>
    </div>
  {/if}
</section>

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

<Modal open={pickerOpen} title="Добавить позицию в состав" wide onclose={() => (pickerOpen = false)}>
  <div class="cand-toolbar">
    <form
      class="cand-search"
      onsubmit={(event) => {
        event.preventDefault();
      }}
    >
      <SearchInput bind:value={pickerQuery} placeholder="Поиск по названию или артикулу" />
    </form>
  </div>

  {#if pickerError}
    <div class="alert">
      <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
      <span>{pickerError}</span>
    </div>
  {/if}

  {#if pickerLoading}
    <div class="center"><Spinner size={26} /></div>
  {:else if pickerQuery.trim() === ''}
    <div class="empty">Введите название или артикул</div>
  {:else if pickerResults.length === 0}
    <div class="empty">Ничего не найдено</div>
  {:else}
    <div class="cand-table">
      <div class="cand-head">
        <span>Название</span>
        <span>Артикул</span>
        <span>Тип</span>
        <span>Выбор</span>
      </div>

      {#each pickerResults as item (item.sid)}
        <div class="cand-row">
          <span class="cand-name">{item.name}</span>
          <span class="cand-article">{item.article || '—'}</span>
          <span class="cand-role">{typeTitleOf(item.type)}</span>
          {#if composition.some((row) => row.item_sid === item.sid)}
            <span class="tag on">в составе</span>
          {:else}
            <button type="button" class="cand-add" onclick={() => addComponent(item)}>Добавить</button>
          {/if}
        </div>
      {/each}
    </div>
  {/if}

  <div class="modal-actions">
    <span class="cand-total">Найдено: {pickerResults.length}</span>
    <Button variant="ghost" onclick={() => (pickerOpen = false)}>Закрыть</Button>
  </div>
</Modal>

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

  .head-actions {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex: 0 0 auto;
    margin-left: auto;
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

  h1 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    line-height: 1.4;
    color: var(--text);
    overflow-wrap: anywhere;
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
    aspect-ratio: var(--photo-ratio, 1 / 1);
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
    overflow: hidden;
    background: var(--fill-tertiary);
  }

  .photo-thumb {
    display: block;
    width: 100%;
    height: 100%;
    padding: 0;
    border: none;
    background: none;
    cursor: zoom-in;
  }

  .photo-item img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: var(--photo-fit, contain);
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
    aspect-ratio: var(--photo-ratio, 1 / 1);
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

  .field select:disabled,
  .field textarea:disabled {
    background: var(--fill-tertiary);
    color: var(--text-description);
    cursor: not-allowed;
  }

  .price-table {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .pt-scroll {
    max-height: 70vh;
    overflow: auto;
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
  }

  .price-table table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    min-width: 420px;
  }

  .price-table th,
  .price-table td {
    padding: 8px 12px;
    border-bottom: 1px solid var(--border-secondary);
    text-align: left;
    font-size: 14px;
    white-space: nowrap;
  }

  .price-table thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: color-mix(in srgb, var(--text) 4%, var(--surface));
    font-weight: 600;
    color: var(--text);
  }

  .price-table tbody tr:last-child td {
    border-bottom: none;
  }

  .price-table tbody tr.current {
    background: var(--focus-ring);
  }

  .pt-wh {
    position: sticky;
    left: 0;
    z-index: 1;
    border-right: 1px solid var(--border-secondary);
    background: var(--surface);
    font-weight: 500;
    color: var(--text);
  }

  .price-table thead th.pt-wh {
    z-index: 3;
    background: color-mix(in srgb, var(--text) 4%, var(--surface));
  }

  .price-table tbody tr.current .pt-wh {
    background: color-mix(in srgb, var(--primary) 8%, var(--surface));
  }

  .pt-wh-name {
    margin-right: var(--space-2);
  }

  .price-table td input {
    width: 100%;
    min-width: 90px;
    height: 32px;
    padding: 0 11px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    font-size: 14px;
    color: var(--text);
    outline: none;
  }

  .price-table td input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(5, 145, 255, 0.1);
  }

  .price-table td input:disabled {
    background: var(--fill-tertiary);
    color: var(--text-description);
  }

  .pt-dash {
    color: var(--text-description);
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

  .level-qty {
    flex: 0 0 120px;
  }

  .level-unit {
    flex: 0 0 auto;
    font-size: 13px;
    color: var(--text-description);
  }

  .level-date {
    flex: 0 0 92px;
    text-align: right;
    font-size: 13px;
    color: var(--text-description);
  }

  .comp-add {
    display: flex;
    justify-content: flex-start;
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

  .row-remove {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 24px;
    height: 24px;
    padding: 0;
    border: none;
    border-radius: var(--radius-sm);
    background: none;
    color: var(--text-description);
    cursor: pointer;
  }

  .row-remove:hover {
    color: var(--danger);
  }

  .empty {
    padding: var(--space-5);
    text-align: center;
    font-size: 14px;
    color: var(--text-description);
  }

  .cand-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--space-3);
  }

  .cand-search {
    flex: 1 1 220px;
    max-width: 320px;
    min-width: 0;
  }

  .cand-table {
    display: flex;
    flex-direction: column;
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-md);
    overflow: hidden;
  }

  .cand-head,
  .cand-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 0.7fr) 130px 110px;
    align-items: center;
    gap: var(--space-3);
    padding: 8px var(--space-3);
  }

  .cand-head {
    background: var(--fill-tertiary);
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
  }

  .cand-row {
    border-top: 1px solid var(--border-secondary);
    font-size: 14px;
  }

  .cand-row:hover {
    background: var(--fill-tertiary);
  }

  .cand-name {
    font-weight: 600;
    color: var(--text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .cand-article,
  .cand-role {
    color: var(--text-description);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .cand-row .tag {
    justify-self: stretch;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 24px;
    padding: 0 7px;
    border-radius: var(--radius-sm);
    font-size: 14px;
  }

  .cand-add {
    padding: 0 7px;
    height: 24px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--primary);
    font: inherit;
    font-size: 14px;
    cursor: pointer;
    transition: color 0.2s ease, border-color 0.2s ease;
  }

  .cand-add:hover:not(:disabled) {
    border-color: var(--primary-hover);
    color: var(--primary-hover);
  }

  .cand-total {
    margin-right: auto;
    font-size: 13px;
    color: var(--text-description);
  }

  .modal-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: var(--space-2);
    margin-top: var(--space-4);
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
</style>
