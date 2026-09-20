import { apiRequest, setTokenProvider, setUnauthorizedHandler } from '../api/client';
import type { LoginResponse, MeResponse, UserProfile } from '../api/types';

const TOKEN_KEY = 'crm_token';
const USER_KEY = 'crm_user';

function readStoredUser(): UserProfile | null {
  const raw = localStorage.getItem(USER_KEY);

  if (!raw) {
    return null;
  }

  try {
    return JSON.parse(raw) as UserProfile;
  } catch {
    return null;
  }
}

class AuthStore {
  token = $state<string | null>(localStorage.getItem(TOKEN_KEY));

  user = $state<UserProfile | null>(readStoredUser());

  capabilities = $state<string[]>([]);

  ready = $state(false);

  get isAuthenticated(): boolean {
    return Boolean(this.token && this.user);
  }

  get level(): number {
    return this.user?.level ?? 0;
  }

  get name(): string {
    return this.user?.name ?? '';
  }

  can(capability: string): boolean {
    return this.capabilities.includes(capability);
  }

  async bootstrap(): Promise<void> {
    if (this.token) {
      try {
        const data = await apiRequest<MeResponse>('/auth/me', { auth: true });
        this.apply(data);
      } catch {
        this.clearSession();
      }
    }

    this.ready = true;
  }

  async login(login: string, password: string): Promise<void> {
    const data = await apiRequest<LoginResponse>('/auth/login', {
      method: 'POST',
      body: { login, password }
    });

    this.token = data.token;
    localStorage.setItem(TOKEN_KEY, data.token);
    this.apply({ user: data.user, capabilities: data.capabilities });
  }

  async logout(): Promise<void> {
    try {
      await apiRequest('/auth/logout', { method: 'POST', auth: true });
    } catch {
      // серверная сессия могла уже истечь
    }

    this.clearSession();
  }

  clearSession(): void {
    this.token = null;
    this.user = null;
    this.capabilities = [];
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
  }

  private apply(data: MeResponse): void {
    this.user = data.user;
    this.capabilities = data.capabilities;
    localStorage.setItem(USER_KEY, JSON.stringify(data.user));
  }
}

export const auth = new AuthStore();

setTokenProvider(() => ({ token: auth.token, ut: null, us: null }));

setUnauthorizedHandler(() => {
  if (auth.isAuthenticated) {
    auth.clearSession();
  }
});
