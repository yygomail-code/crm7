const AUDIT_LABELS: Record<string, string> = {
  'auth.login': 'Вход в систему',
  'auth.register': 'Регистрация',
  'auth.password_change': 'Смена пароля',
  'auth.password_reset': 'Восстановление пароля',
  'auth.password_reset_blocked': 'Восстановление пароля заблокировано',
  'role.update': 'Изменение прав роли',
  'profile.update': 'Правка профиля',
  'legal.save': 'Сохранение юридического документа',
  'settings.database': 'Изменение настроек базы данных',
  'user.create': 'Создание пользователя',
  'user.update': 'Правка пользователя',
  'user.block': 'Блокировка пользователя',
  'user.unblock': 'Разблокировка пользователя',
  'user.reset_password': 'Сброс пароля',
  'client.activate': 'Подтверждение клиента',
  'client.reject': 'Отказ клиенту',
  'client.block': 'Блокировка клиента',
  'client.unblock': 'Разблокировка клиента',
  'client.update': 'Правка клиента',
  'client.claim': 'Клиент взят менеджером',
  'client.assign': 'Назначение менеджера клиенту',
  'client.transfer': 'Передача клиента',
  'client.transfer.accept': 'Передача принята',
  'client.transfer.decline': 'Передача отклонена',
  'client.transfer.cancel': 'Передача отменена',
  'request.create': 'Создание заявки',
  'request.transition': 'Смена статуса заявки',
  'request.edit': 'Правка заявки',
  'request.items': 'Изменение состава заявки',
  'request.claim': 'Заявка взята в работу',
  'request.assign': 'Передача заявки',
  'request.comment': 'Комментарий к заявке',
  'substitution.create': 'Создание замещения',
  'substitution.end': 'Завершение замещения',
  'stocks.import': 'Импорт остатков',
  'stocks.export': 'Экспорт остатков',
  'stocks.item.create': 'Добавление позиции склада',
  'stocks.item.update': 'Правка позиции склада',
  'stocks.warehouse.rename': 'Переименование склада',
  'stocks.manage': 'Управление складом'
};

const ENTITY_LABELS: Record<string, string> = {
  user: 'пользователь',
  request: 'заявка',
  client: 'клиент',
  stocks: 'склад',
  roles: 'роль',
  substitution: 'замещение',
  legal: 'документ',
  settings: 'настройки'
};

const EMAIL_TYPE_LABELS: Record<string, string> = {
  request_new: 'Новая заявка',
  request_claim: 'Заявка взята в работу',
  request_status: 'Статус заявки',
  request_comment: 'Комментарий',
  request_assigned: 'Передача заявки',
  request_transferred: 'Заявка передана',
  request_assign: 'Смена менеджера',
  report_scheduled: 'Отчёт по расписанию',
  substitution_created: 'Создано замещение',
  substitution_ended: 'Замещение завершено',
  user_approved: 'Регистрация подтверждена',
  user_rejected: 'Регистрация отклонена'
};

const QUEUE_STATUS_LABELS: Record<string, string> = {
  pending: 'в очереди',
  processing: 'отправляется',
  sent: 'отправлено',
  failed: 'ошибка'
};

export function auditLabel(action: string): string {
  return AUDIT_LABELS[action] ?? action;
}

export function entityLabel(entity: string): string {
  return ENTITY_LABELS[entity] ?? entity;
}

export function emailTypeLabel(code: string): string {
  return EMAIL_TYPE_LABELS[code] ?? code;
}

export function queueStatusLabel(status: string): string {
  return QUEUE_STATUS_LABELS[status] ?? status;
}
