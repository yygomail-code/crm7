export interface LegacyEnvelope<T = unknown> {
  status?: 'ok' | 'error';
  level?: number;
  res?: T;
  error?: string;
}

export interface LoginData {
  utid: string;
  usid: string;
  name: string | null;
  SID: string;
}

export interface ApiResult<T> {
  ok: boolean;
  level: number;
  data: T | null;
  error: string | null;
}

export interface UserProfile {
  id: number;
  sid: string;
  login: string;
  name: string;
  level: number;
  email: string;
  phone: string;
  company: string;
  position: string;
}

export interface LoginResponse {
  token: string;
  expires_at: string;
  user: UserProfile;
  capabilities: string[];
}

export interface MeResponse {
  user: UserProfile;
  capabilities: string[];
}

export interface RequestStatus {
  code: string;
  title: string;
  sort: number;
  color: string;
  is_final: boolean;
}

export interface RequestItem {
  id: number;
  number: string;
  subject: string;
  body: string;
  status: { code: string; title: string; color: string; is_final: boolean };
  priority: number;
  client: { id: number; name: string; email: string; phone: string };
  manager: { id: number; name: string } | null;
  due_at: string | null;
  is_overdue: boolean;
  first_response_at: string | null;
  resolved_at: string | null;
  closed_at: string | null;
  version: number;
  created_at: string;
  updated_at: string;
}

export interface RequestHistoryItem {
  id: number;
  user: { id: number; name: string; level: number };
  from: { code: string; title: string } | null;
  to: { code: string; title: string } | null;
  comment: string;
  created_at: string;
}

export interface RequestComment {
  id: number;
  user: { id: number; name: string; level: number };
  body: string;
  is_internal: boolean;
  created_at: string;
}

export interface ReportTotals {
  created_in_period: number;
  closed_in_period: number;
  open_now: number;
  overdue_now: number;
  unassigned: number;
}

export interface ReportStatusRow {
  code: string;
  title: string;
  color: string;
  is_final: boolean;
  count: number;
}

export interface ReportManagerRow {
  manager_id: number;
  manager_name: string;
  created_in_period: number;
  open_now: number;
  closed_in_period: number;
  overdue_now: number;
  avg_response_minutes: number | null;
  avg_resolution_minutes: number | null;
}

export interface ReportClientRow {
  client_id: number;
  client_name: string;
  company: string;
  created_in_period: number;
  open_now: number;
  closed_in_period: number;
  overdue_now: number;
  last_request_at: string | null;
}

export interface SalesLeadQuery {
  query: string;
  count: number;
  zero_results: number;
}

export interface SalesLeadWarehouse {
  warehouse_id: number;
  warehouse_name: string;
  views: number;
  searches: number;
  exports: number;
}

export interface SalesLeadClient {
  client_id: number;
  client_name: string;
  company: string;
  views: number;
  searches: number;
  exports: number;
  last_activity_at: string;
}

export interface SalesLeadRecent {
  action: string;
  query: string;
  result_count: number | null;
  user_name: string;
  user_level: number;
  warehouse_name: string;
  created_at: string;
}

export interface SalesLeadsReport {
  period: { from: string; to: string };
  top_queries: SalesLeadQuery[];
  zero_result_queries: SalesLeadQuery[];
  warehouses: SalesLeadWarehouse[];
  clients: SalesLeadClient[];
  recent: SalesLeadRecent[];
}

export interface ReportPoint {
  date: string;
  created: number;
  closed: number;
}

export interface ReportSummary {
  period: { from: string; to: string };
  totals: ReportTotals;
  by_status: ReportStatusRow[];
  by_manager: ReportManagerRow[];
  by_client: ReportClientRow[];
  dynamics: ReportPoint[];
}

export interface MyReportSummary {
  period: { from: string; to: string };
  requests: {
    total: number;
    closed: number;
    overdue: number;
    by_status: { code: string; title: string; color: string; count: number }[];
    by_priority: { priority: number; count: number }[];
  };
  items: { total: number; quantity: number };
  dynamics: { date: string; requests: number; items: number }[];
  warehouses: { id: number; name: string }[];
  statuses: { code: string; title: string }[];
  priorities: { value: number; title: string }[];
}

export interface ReportSchedule {
  id: number;
  title: string;
  report_type: string;
  frequency: string;
  time_of_day: string;
  day_of_week: number | null;
  day_of_month: number | null;
  recipients: { id: number; name: string; email: string }[];
  extra_emails: string[];
  is_active: boolean;
  last_sent_at: string | null;
  created_at: string;
}

export interface EmailPreset {
  code: string;
  title: string;
  host: string;
  port: number;
  encryption: string;
}

export interface EmailSettings {
  enabled: boolean;
  host: string;
  port: number;
  encryption: string;
  username: string;
  from: string;
  from_name: string;
  has_password: boolean;
  presets: EmailPreset[];
}

export interface EmailTemplate {
  code: string;
  subject: string;
  body: string;
  updated_at: string;
}

export interface EmailQueueItem {
  id: number;
  to: string;
  subject: string;
  status: string;
  attempts: number;
  last_error: string;
  created_at: string;
  sent_at: string | null;
}

export interface RequestAttachment {
  id: number;
  name: string;
  mime: string;
  size: number;
  user: { id: number; name: string };
  created_at: string;
}

export interface NotificationItem {
  id: number;
  type: string;
  title: string;
  body: string;
  request_id: number | null;
  is_read: boolean;
  created_at: string;
}

export interface ActivityType {
  code: string;
  title: string;
  audience: string;
  sort: number;
}

export interface RequestActivity {
  id: number;
  type: { code: string; title: string };
  user: { id: number; name: string; level: number };
  body: string;
  created_at: string;
}

export interface RequestAssignment {
  id: number;
  from: { id: number; name: string } | null;
  to: { id: number; name: string } | null;
  user: { id: number; name: string };
  comment: string;
  created_at: string;
}

export interface RequestDetail {
  request: RequestItem;
  items: RequestItemRow[];
  history: RequestHistoryItem[];
  comments: RequestComment[];
  assignments: RequestAssignment[];
  activities: RequestActivity[];
  views: ViewEntry[];
  previous_manager: { id: number; name: string } | null;
  can: {
    transition: boolean;
    cancel: boolean;
    claim: boolean;
    assign: boolean;
    comment: boolean;
    comment_internal: boolean;
    activity: boolean;
  };
}

export interface RequestListResponse {
  items: RequestItem[];
  total: number;
  page: number;
  per_page: number;
}

export interface RequestSummary {
  by_status: Record<string, number>;
  total: number;
  open: number;
  closed: number;
  overdue: number;
  statuses: RequestStatus[];
}

export interface ClientItem {
  id: number;
  login: string;
  name: string;
  email: string;
  phone: string;
  company: string;
  inn: string;
  position: string;
  manager: { manager_id: number; manager_name: string; is_primary: boolean } | null;
}

export interface ManagerItem {
  id: number;
  login: string;
  name: string;
  level: number;
  email: string;
}

export interface SystemSettings {
  sla_reaction_hours: number;
  sla_resolution_hours: number;
  spf_checklist: boolean;
  spf_steps: string[];
}

export interface AdminUser {
  id: number;
  login: string;
  name: string;
  email: string;
  phone: string;
  company: string;
  inn: string;
  position: string;
  level: number;
  active: boolean;
  reg_state: string;
  created_at: string;
  last_seen_at: string | null;
}

export interface RoleInfo {
  level: number;
  title: string;
  capabilities: string[];
}

export interface AuditEntry {
  id: number;
  user: { id: number; name: string } | null;
  action: string;
  entity: string;
  entity_id: string;
  data: Record<string, unknown>;
  ip: string;
  created_at: string;
}

export interface StockWarehouse {
  id: number;
  name: string;
  sort: number;
  positions: number;
  actual_date: string | null;
  is_personal: boolean;
}

export interface StockLevel {
  id: number;
  name: string;
  unit: string;
  quantity: number;
  actual_date: string | null;
}

export interface StockImportJob {
  id: number;
  file_name: string;
  actual_date: string;
  status: string;
  rows_total: number;
  rows_imported: number;
  rows_skipped: number;
  errors: string;
  user: string;
  created_at: string;
  finished_at: string | null;
}

export interface StockUpdate {
  id: number;
  actual_date: string;
  file: string;
  status: string;
  rows_count: number;
  created_at: string;
}

export interface ChatThread {
  id: number;
  client: { id: number; name: string };
  manager: { id: number; name: string } | null;
  last_message: { body: string; created_at: string; user: { id: number; name: string } } | null;
  last_message_at: string;
  unread: number;
}

export interface ChatAttachment {
  id: number;
  name: string;
  mime: string;
  size: number;
}

export interface ChatMessage {
  id: number;
  body: string;
  created_at: string;
  user: { id: number; name: string; level: number };
  is_mine: boolean;
  attachments: ChatAttachment[];
}

export interface PendingClient {
  id: number;
  name: string;
  email: string;
  phone: string;
  company: string;
  inn: string;
  position: string;
  registered_at: string;
}

export interface ClientHistoryItem {
  id: number;
  event: string;
  comment: string;
  actor: { id: number; name: string; level: number } | null;
  created_at: string;
}

export interface ClientCardRequest {
  id: number;
  number: string;
  subject: string;
  status: { code: string; title: string; color: string; is_final: boolean };
  manager: { id: number; name: string } | null;
  created_at: string;
  due_at: string | null;
  is_overdue: boolean;
}

export interface RequestItemRow {
  id: number;
  warehouse_id: number | null;
  warehouse_name: string;
  name: string;
  unit: string;
  quantity: number;
}

export interface ViewEntry {
  id: number;
  action: string;
  user: { id: number; name: string; level: number };
  created_at: string;
}

export interface ClientCard {
  client: {
    id: number;
    login: string;
    name: string;
    email: string;
    phone: string;
    company: string;
    inn: string;
    position: string;
    active: boolean;
    reg_state: string;
    registered_at: string;
    last_seen_at: string | null;
    manager: { manager_id: number; manager_name: string; is_primary: boolean } | null;
  };
  stats: { total: number; open: number; closed: number; overdue: number };
  history: ClientHistoryItem[];
  views: ViewEntry[];
  requests: ClientCardRequest[];
}

export interface Substitution {
  id: number;
  manager: { id: number; name: string };
  substitute: { id: number; name: string };
  date_from: string;
  date_to: string;
  reason: string;
  is_active: boolean;
  requests_count: number;
  created_at: string;
}
