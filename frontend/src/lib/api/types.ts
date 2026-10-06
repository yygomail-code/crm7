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
  price_type_id: number | null;
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
  client: { id: number; name: string; email: string; phone: string; inn: string };
  manager: { id: number; name: string } | null;
  items_count: number;
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
  user: { id: number; name: string; level: number; position: string };
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
  user: { id: number; name: string; level: number; position: string };
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
  prices: { enabled: boolean; type: { id: number; title: string } | null; total: number | null };
  history: RequestHistoryItem[];
  comments: RequestComment[];
  assignments: RequestAssignment[];
  activities: RequestActivity[];
  views: ViewEntry[];
  previous_manager: { id: number; name: string } | null;
  can: {
    transition: boolean;
    transition_to: string[];
    cancel: boolean;
    claim: boolean;
    assign: boolean;
    comment: boolean;
    comment_internal: boolean;
    activity: boolean;
    edit: boolean;
    priority: boolean;
    meta: boolean;
    edit_text: boolean;
    edit_items: boolean;
  };
  chat: { thread_id: number; can_post: boolean; unread: number };
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
  active: boolean;
  reg_state: string;
  registered_at: string;
  requests_total: number;
  manager: { id: number; name: string } | null;
  transfer_to_me: { id: number; from_name: string; date_from: string | null; date_to: string | null } | null;
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
  sales_enabled: boolean;
  email_export_enabled: boolean;
  mail_configured: boolean;
  stock_reserve_enabled: boolean;
  stock_allow_zero: boolean;
  prices_enabled: boolean;
  groups_enabled: boolean;
  requests_enabled: boolean;
  cart_enabled: boolean;
  substitutions_enabled: boolean;
  manager_assign_enabled: boolean;
  app_title: string;
  client_label: string;
  spf_steps: string[];
}

export interface DatabaseSettings {
  host: string;
  port: number;
  database: string;
  user: string;
  has_password: boolean;
  source: 'file' | 'env';
  file: string;
  warnings?: string[];
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
  price_type_id: number | null;
  price_type_title: string | null;
}

export interface PriceType {
  id: number;
  code: string;
  title: string;
  sort: number;
  users_count?: number;
  prices_count?: number;
}

export interface ItemGroup {
  id: number;
  title: string;
  sort: number;
  positions_count?: number;
}

export interface PricePreview {
  enabled: boolean;
  type: { id: number; title: string } | null;
  items: { price: number | null; sum: number | null }[];
  total: number | null;
}

export interface RoleInfo {
  level: number;
  title: string;
  capabilities: string[];
}

export interface WarehouseReportRow {
  warehouse_id: number;
  warehouse_name: string;
  positions: number;
  zero_positions: number;
  stock_quantity: number;
  requests_count: number;
  clients_count: number;
  requested_quantity: number;
}

export interface WarehouseReportTopItem {
  name: string;
  total_quantity: number;
  requests_count: number;
  by_warehouse: { warehouse_id: number; warehouse_name: string; quantity: number }[];
}

export interface WarehouseReport {
  period: { from: string; to: string };
  warehouses: WarehouseReportRow[];
  top_items: WarehouseReportTopItem[];
}

export interface RoleCapability {
  code: string;
  title: string;
}

export interface RolesResponse {
  items: RoleInfo[];
  catalog: RoleCapability[];
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

export interface SearchQueryStat {
  query: string;
  searches: number;
  last_at: string;
}

export interface SearchLogEntry {
  id: number;
  query: string;
  results: number;
  user_id: number;
  user_name: string;
  created_at: string;
}

export interface SearchLogResponse {
  stats: { total: number; users: number; unique_queries: number };
  top: SearchQueryStat[];
  recent: SearchLogEntry[];
}

export interface StockWarehouse {
  id: number;
  name: string;
  sort: number;
  positions: number;
  actual_date: string | null;
  is_personal: boolean;
}

export interface StockPhoto {
  id: number;
}

export interface StockLevel {
  id: number;
  name: string;
  unit: string;
  quantity: number;
  description: string;
  actual_date: string | null;
  price: number | null;
  prices?: Record<string, number>;
  group_id: number | null;
  group_title: string;
  warehouse_id: number;
  warehouse_name: string;
  photos?: StockPhoto[];
}

export interface StockItemPayload {
  name: string;
  unit: string;
  quantity: number;
  description: string;
  prices?: Record<string, number | null>;
  group_id?: number | null;
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
  kind: 'client' | 'staff';
  can_post: boolean;
  client: { id: number; name: string };
  manager: { id: number; name: string } | null;
  peer: { id: number; name: string } | null;
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
  request: { id: number; number: string } | null;
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
  stock_level_id: number | null;
  name: string;
  unit: string;
  description: string;
  quantity: number;
  price: number | null;
  price_type_id: number | null;
  sum: number | null;
}

export interface ViewEntry {
  id: number;
  action: string;
  user: { id: number; name: string; level: number };
  created_at: string;
}

export interface ClientManagerInfo {
  id: number;
  name: string;
  level: number;
  assigned_at: string;
  assigned_by: { id: number; name: string } | null;
  temporary_until: string | null;
}

export interface ClientTransferInfo {
  id: number;
  client_id: number;
  client_name: string;
  from: { id: number; name: string } | null;
  to: { id: number; name: string } | null;
  date_from: string | null;
  date_to: string | null;
  started_at: string | null;
  status: string;
  comment: string;
  created_by: { id: number; name: string };
  created_at: string;
  incoming: boolean;
  can_accept: boolean;
  can_cancel: boolean;
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
  };
  manager: ClientManagerInfo | null;
  transfer: ClientTransferInfo | null;
  can: { manage: boolean; assign: boolean; claim: boolean; accept: boolean; cancel: boolean };
  stats: {
    total: number;
    open: number;
    closed: number;
    overdue: number;
    by_status: Record<string, number>;
  };
  interests: ClientInterest[];
  history: ClientHistoryItem[];
  views: ViewEntry[];
  requests: ClientCardRequest[];
}

export interface ClientInterest {
  name: string;
  unit: string;
  description: string;
  warehouse_name: string;
  orders: number;
  total_qty: number;
  last_at: string;
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
