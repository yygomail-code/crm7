export interface RouteState {
  path: string;
  query: URLSearchParams;
}

function normalize(path: string): string {
  if (!path) return '/';
  return path.startsWith('/') ? path : `/${path}`;
}

function parse(hash: string): RouteState {
  const raw = hash.startsWith('#') ? hash.slice(1) : hash;
  const [path, queryString = ''] = raw.split('?');
  return { path: normalize(path), query: new URLSearchParams(queryString) };
}

class Router {
  current = $state<RouteState>(parse(window.location.hash));

  constructor() {
    window.addEventListener('hashchange', () => {
      this.current = parse(window.location.hash);
    });
  }

  navigate(to: string, replace = false): void {
    const target = `#${normalize(to)}`;
    if (replace) {
      window.location.replace(target);
      this.current = parse(target);
      return;
    }
    window.location.hash = normalize(to);
  }
}

export const router = new Router();

export { matchRoute } from './match-route';
