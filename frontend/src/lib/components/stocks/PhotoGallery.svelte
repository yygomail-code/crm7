<script lang="ts">
  import { loadItemPhotoUrl } from '../../api/stocks';
  import type { StockPhoto } from '../../api/types';
  import Icon from '../ui/Icon.svelte';

  interface Props {
    photos: StockPhoto[];
    variant?: 'list' | 'tile';
    onopen?: () => void;
    flush?: boolean;
  }

  let { photos, variant = 'list', onopen, flush = false }: Props = $props();

  let index = $state(0);
  let urls = $state<Record<number, string | null>>({});
  let touchX = 0;

  $effect(() => {
    let cancelled = false;

    for (const photo of photos) {
      if (urls[photo.id] !== undefined) {
        continue;
      }

      void loadItemPhotoUrl(photo.id).then((url) => {
        if (!cancelled) {
          urls[photo.id] = url;
        }
      });
    }

    return () => {
      cancelled = true;
    };
  });

  $effect(() => {
    if (index >= photos.length) {
      index = 0;
    }
  });

  const isCarousel = $derived(variant === 'tile');
  const currentId = $derived(isCarousel ? (photos[index]?.id ?? null) : (photos[0]?.id ?? null));
  const currentUrl = $derived(currentId === null ? null : urls[currentId] ?? null);

  function open(): void {
    onopen?.();
  }

  function onKeydown(event: KeyboardEvent): void {
    if (onopen !== undefined && (event.key === 'Enter' || event.key === ' ')) {
      event.preventDefault();
      open();
    }
  }

  function prev(): void {
    if (photos.length > 1) {
      index = (index - 1 + photos.length) % photos.length;
    }
  }

  function next(): void {
    if (photos.length > 1) {
      index = (index + 1) % photos.length;
    }
  }

  function onTouchStart(event: TouchEvent): void {
    if (!isCarousel) {
      return;
    }

    touchX = event.touches[0]?.clientX ?? 0;
  }

  function onTouchEnd(event: TouchEvent): void {
    if (!isCarousel) {
      return;
    }

    const dx = (event.changedTouches[0]?.clientX ?? 0) - touchX;

    if (Math.abs(dx) < 30) {
      return;
    }

    if (dx < 0) {
      next();
    } else {
      prev();
    }
  }
</script>

<!-- svelte-ignore a11y_no_noninteractive_tabindex -->
<div
  class="gallery"
  class:tile={variant === 'tile'}
  class:flush
  class:clickable={onopen !== undefined}
  role={onopen !== undefined ? 'button' : 'group'}
  tabindex={onopen !== undefined ? 0 : undefined}
  aria-label={onopen !== undefined ? 'Подробнее о позиции' : 'Фото товара'}
  onclick={open}
  onkeydown={onKeydown}
  ontouchstart={onTouchStart}
  ontouchend={onTouchEnd}
>
  {#if currentUrl}
    <img src={currentUrl} alt="" loading="lazy" />
  {:else}
    <div class="placeholder" aria-hidden="true">
      <Icon name="camera" size={variant === 'tile' ? 28 : 18} />
    </div>
  {/if}

  {#if isCarousel && photos.length > 1}
    <button
      type="button"
      class="nav prev"
      aria-label="Предыдущее фото"
      onclick={(event) => {
        event.stopPropagation();
        prev();
      }}>‹</button
    >
    <button
      type="button"
      class="nav next"
      aria-label="Следующее фото"
      onclick={(event) => {
        event.stopPropagation();
        next();
      }}>›</button
    >
    <span class="counter">{index + 1}/{photos.length}</span>
  {/if}
</div>

<style>
  .gallery {
    position: relative;
    flex: 0 0 auto;
    width: 56px;
    height: 56px;
    border-radius: var(--radius-sm);
    overflow: hidden;
    background: var(--bg);
    border: 1px solid var(--border);
  }

  .gallery.tile {
    width: 100%;
    height: auto;
    aspect-ratio: 16 / 9;
  }

  .gallery.flush {
    border: none;
    border-radius: 0;
  }

  .gallery.clickable {
    cursor: pointer;
  }

  img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    background: var(--bg);
    color: var(--muted);
  }

  .nav {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 22px;
    height: 22px;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.45);
    color: #fff;
    font-size: 15px;
    line-height: 1;
    cursor: pointer;
    opacity: 0;
    transition: opacity 0.15s ease;
  }

  .gallery:hover .nav {
    opacity: 1;
  }

  .nav.prev {
    left: 4px;
  }

  .nav.next {
    right: 4px;
  }

  .counter {
    position: absolute;
    right: 4px;
    bottom: 4px;
    padding: 1px 5px;
    border-radius: 999px;
    background: rgba(0, 0, 0, 0.55);
    color: #fff;
    font-size: 10px;
  }
</style>
