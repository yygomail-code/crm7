<script lang="ts">
  import { APP_NAME, APP_VERSION, CONTACTS, SITE_URL, SITE_LABEL } from '../lib/about';
  import Logo from '../lib/components/ui/Logo.svelte';
  import { router } from '../lib/router.svelte';
</script>

<section class="page">
  <div class="head">
    <h1>О программе</h1>
  </div>

  <div class="card intro">
    <Logo size={64} />
    <div class="intro-text">
      <div class="app-name">{APP_NAME} — заявки, чат и склад</div>
      <div class="version">Версия {APP_VERSION}</div>
      <p>
        Единое окно для заявок клиентов, переписки с менеджером, остатков складов и отчётов.
        Работает в браузере телефона и компьютера, можно добавить на главный экран.
      </p>
    </div>
  </div>

  <div class="card">
    <h2>Краткая справка</h2>

    <h3>Клиенту</h3>
    <ul>
      <li>При входе сразу открываются «Заявки»: «Новая заявка» или из корзины на складах; статус и история — в карточке заявки, там же можно отменить незакрытую заявку.</li>
      <li>Складские остатки: наличие по доступным складам, поиск (клик по пустому полю — последние запросы), фильтры (количество, сортировка) — в модалке «Фильтры» и сохраняются на устройстве; кнопка с корзиной добавляет позицию (количество — «−/+», строки в корзине подсвечены).</li>
      <li>Мои отчёты: сколько заявок и позиций за период, график по дням (переключение «Заявки/Позиции»), фильтры (период, статус, приоритет, склад) — в модалке «Фильтры», поиск по позиции отдельно; отчёт можно скачать или отправить на почту.</li>
      <li>Чат: переписка со своим менеджером, можно прикреплять файлы до 10 МБ.</li>
      <li>Уведомления: колокольчик в шапке; какие события присылать — в профиле.</li>
    </ul>

    <h3>Менеджеру</h3>
    <ul>
      <li>Заявки: фильтры (статус, менеджер, склад, период, сортировка) — в модалке «Фильтры» (сохраняются на устройстве), поиск с историей; смена статуса с комментарием, передачи и комментарии; позиции заявки с указанием складов.</li>
      <li>Клиенты: подтверждение регистрации, карточка клиента (контакты, интересы, история, заявки), закрепление клиента за менеджером — «Взять себе», «Назначить» (РОП/админ), «Передать» сотруднику или в пул без менеджера (навсегда или на период «с – до», менеджер меняется с даты передачи); управлять профилем клиента может его менеджер или замещающий, а отвечать в чате — любой сотрудник (заявку клиента может вести не привязанный менеджер).</li>
      <li>Замещения: на время отпуска заявки и чаты клиентов уходят заместителю и возвращаются автоматически, клиенты получают уведомление.</li>
      <li>Складские остатки: наличие, фильтры и сортировка, импорт из 1С (xls, xlsx, csv), история импорта, счётчики найденного по складам.</li>
      <li>Отчёты: сводка (создано/закрыто/открыто/просрочено), по статусам, менеджерам и клиентам, динамика, целевые продажи; расписание отправки.</li>
    </ul>

    <h3>Администратору</h3>
    <ul>
      <li>Пользователи и роли, журнал действий, юридические документы, настройки SLA и почты, справочники, расписание отчётов.</li>
    </ul>

    <h3>Экспорт и отправка</h3>
    <ul>
      <li>Кнопка «Экспорт» (складские остатки): формат на выбор — XLSX, XLS, CSV, TXT, PDF.</li>
      <li>Чекбокс «Отправить на почту» — файл придёт письмом с вложением на адрес из профиля (вместо скачивания).</li>
    </ul>

    <h3>Полезно знать</h3>
    <ul>
      <li>Поиск: крестик в поле очищает его и сразу показывает всё, а клик по пустому полю открывает последние запросы.</li>
      <li>
        Корзина хранится на устройстве отдельно для каждого пользователя; после создания заявки из
        корзины она очищается.
      </li>
      <li>Главной страницы нет — сводная информация собрана в «Отчётах» (и в «Моих отчётах»).</li>
      <li>Программу можно добавить на главный экран телефона — она откроется как приложение.</li>
    </ul>
  </div>

  <div class="card">
    <h2>Документы</h2>
    <ul class="docs">
      <li>
        <button type="button" class="doc-link" onclick={() => router.navigate('/legal/privacy')}>
          Политика обработки персональных данных
        </button>
      </li>
      <li>
        <button type="button" class="doc-link" onclick={() => router.navigate('/legal/terms')}>
          Пользовательское соглашение
        </button>
      </li>
      <li>
        <button type="button" class="doc-link" onclick={() => router.navigate('/legal/consent')}>
          Согласие на обработку персональных данных
        </button>
      </li>
    </ul>
  </div>

  <div class="card">
    <h2>Официальный сайт</h2>
    <p class="site">
      <a class="site-link" href={SITE_URL} target="_blank" rel="noopener">{SITE_LABEL}</a>
    </p>
    <ul class="site-about">
      <li>скачать приложение и обновления;</li>
      <li>попробовать приложение без регистрации;</li>
      <li>почитать документацию.</li>
    </ul>
  </div>

  <div class="card">
    <h2>Поддержка и обратная связь</h2>
    <p class="support-note">
      Вопросы по работе программы, сообщения об ошибках и предложения — пишите или звоните:
    </p>
    <ul class="contacts">
      {#each CONTACTS as contact (contact.label)}
        <li>
          <span class="contact-label">{contact.label}</span>
          {#if contact.href}
            <a class="contact-value" href={contact.href}>{contact.value}</a>
          {:else}
            <span class="contact-value">{contact.value}</span>
          {/if}
        </li>
      {/each}
    </ul>
  </div>
</section>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    flex-wrap: wrap;
  }

  h1 {
    margin: 0;
    font-size: 22px;
  }

  .card {
    padding: var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
  }

  .intro {
    display: flex;
    align-items: center;
    gap: var(--space-4);
  }

  .app-name {
    font-size: 17px;
    font-weight: 600;
  }

  .version {
    font-size: 13px;
    color: var(--muted);
  }

  .intro-text p {
    margin: var(--space-2) 0 0;
    font-size: 14px;
    color: var(--muted);
  }

  h2 {
    margin: 0 0 var(--space-3);
    font-size: 16px;
  }

  h3 {
    margin: var(--space-4) 0 var(--space-2);
    font-size: 14px;
  }

  h3:first-of-type {
    margin-top: 0;
  }

  ul {
    margin: 0;
    padding-left: 18px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 14px;
  }

  .docs {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
    font-size: 14px;
  }

  .doc-link {
    padding: 0;
    border: none;
    background: none;
    color: var(--primary);
    font: inherit;
    text-align: left;
    cursor: pointer;
  }

  .doc-link:hover {
    text-decoration: underline;
  }

  .site {
    margin: 0 0 var(--space-2);
  }

  .site-link {
    font-size: 16px;
    font-weight: 600;
    color: var(--primary);
    text-decoration: none;
  }

  .site-link:hover {
    text-decoration: underline;
  }

  .site-about {
    margin: 0;
    padding-left: 20px;
    color: var(--muted);
    font-size: 14px;
  }

  .support-note {
    margin: 0 0 var(--space-3);
    font-size: 14px;
    color: var(--muted);
  }

  .contacts {
    list-style: none;
    padding-left: 0;
  }

  .contacts li {
    display: flex;
    gap: var(--space-3);
  }

  .contact-label {
    min-width: 80px;
    color: var(--muted);
  }

  .contact-value {
    font-weight: 500;
    color: inherit;
  }

  a.contact-value {
    color: var(--primary);
    text-decoration: none;
  }

  a.contact-value:hover {
    text-decoration: underline;
  }
</style>
