const SHOW_DELAY = 2500;

export function tooltip(node: HTMLElement, text: string | null | undefined) {
  let current = (text ?? '').trim();
  let showTimer: ReturnType<typeof setTimeout> | null = null;
  let bubble: HTMLDivElement | null = null;

  function clearShowTimer(): void {
    if (showTimer !== null) {
      clearTimeout(showTimer);
      showTimer = null;
    }
  }

  function hide(): void {
    clearShowTimer();

    if (bubble !== null) {
      bubble.remove();
      bubble = null;
    }
  }

  function place(): void {
    if (bubble === null) {
      return;
    }

    const rect = node.getBoundingClientRect();
    const width = bubble.offsetWidth;
    const height = bubble.offsetHeight;

    let left = rect.left;
    let top = rect.bottom + 8;

    if (left + width > window.innerWidth - 8) {
      left = Math.max(8, window.innerWidth - width - 8);
    }

    if (top + height > window.innerHeight - 8) {
      top = Math.max(8, rect.top - height - 8);
    }

    bubble.style.left = `${Math.max(8, left)}px`;
    bubble.style.top = `${top}px`;
  }

  function show(): void {
    showTimer = null;

    if (current === '' || bubble !== null || !node.isConnected) {
      return;
    }

    bubble = document.createElement('div');
    bubble.className = 'crm-tooltip';
    bubble.textContent = current;
    bubble.setAttribute('role', 'tooltip');
    document.body.appendChild(bubble);
    place();
  }

  function schedule(): void {
    if (current === '') {
      return;
    }

    clearShowTimer();
    showTimer = setTimeout(show, SHOW_DELAY);
  }

  function onEnter(): void {
    schedule();
  }

  function onMove(): void {
    if (bubble !== null) {
      hide();

      return;
    }

    schedule();
  }

  function onLeave(): void {
    hide();
  }

  function onScroll(): void {
    hide();
  }

  node.addEventListener('mouseenter', onEnter);
  node.addEventListener('mousemove', onMove);
  node.addEventListener('mouseleave', onLeave);
  node.addEventListener('mousedown', hide);
  node.addEventListener('focus', hide);
  window.addEventListener('scroll', onScroll, true);

  return {
    update(next: string | null | undefined): void {
      current = (next ?? '').trim();
      hide();
    },
    destroy(): void {
      hide();
      node.removeEventListener('mouseenter', onEnter);
      node.removeEventListener('mousemove', onMove);
      node.removeEventListener('mouseleave', onLeave);
      node.removeEventListener('mousedown', hide);
      node.removeEventListener('focus', hide);
      window.removeEventListener('scroll', onScroll, true);
    }
  };
}
