import { spawnSync } from 'node:child_process';
import { createInterface } from 'node:readline/promises';
import { argv, exit, stdin, stdout } from 'node:process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const PUSH_ATTEMPTS = Math.max(1, Number(process.env.CRM_PUSH_RETRIES ?? 3));
const PUSH_DELAY_MS = Math.max(0, Number(process.env.CRM_PUSH_DELAY_MS ?? 5000));

function git(args, { input, inherit = false } = {}) {
  const result = spawnSync('git', args, {
    cwd: ROOT,
    ...(inherit ? { stdio: 'inherit' } : { encoding: 'utf8' }),
    ...(input === undefined ? {} : { input })
  });

  if (result.error) {
    throw result.error;
  }

  return result;
}

function mustGit(args, options) {
  const result = git(args, options);

  if (result.status !== 0) {
    const detail = `${result.stderr ?? ''}${result.stdout ?? ''}`.trim();
    throw new Error(detail || `git ${args.join(' ')} завершился с кодом ${result.status}`);
  }

  return result;
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function defaultMessage() {
  const now = new Date();
  const pad = (value) => String(value).padStart(2, '0');

  return `CRM7: ручной коммит — ${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(
    now.getDate()
  )} ${pad(now.getHours())}:${pad(now.getMinutes())}`;
}

async function readMessage() {
  const fromArgs = argv.slice(2).join(' ').trim();

  if (fromArgs) {
    return fromArgs;
  }

  const fallback = defaultMessage();

  if (!stdin.isTTY) {
    return fallback;
  }

  const rl = createInterface({ input: stdin, output: stdout });

  try {
    const answer = (await rl.question(`Сообщение коммита [${fallback}]: `)).trim();
    return answer || fallback;
  } finally {
    rl.close();
  }
}

function pushHint(output) {
  if (/authentication|invalid username|invalid token|permission denied|403/i.test(output)) {
    return 'Обновите токен GitHub в Git Credential Manager или используйте PAT.';
  }

  if (/could not resolve host|failed to connect|connection (reset|closed|timed out)|unable to access/i.test(output)) {
    return 'Похоже, нет сети/VPN. Коммит сохранён локально — запустите скрипт ещё раз, когда связь появится.';
  }

  if (/does not appear to be a git repository|could not read from remote repository|no such remote/i.test(output)) {
    return 'Не настроен remote origin или нет доступа к репозиторию.';
  }

  return '';
}

async function push(branch) {
  for (let attempt = 1; attempt <= PUSH_ATTEMPTS; attempt += 1) {
    console.log(`Пуш origin/${branch} (попытка ${attempt}/${PUSH_ATTEMPTS})...`);

    const result = git(['push', '-u', 'origin', branch]);
    const output = `${result.stdout ?? ''}${result.stderr ?? ''}`.trim();

    if (output) {
      console.log(output);
    }

    if (result.status === 0) {
      console.log('Пуш выполнен.');
      return;
    }

    const hint = pushHint(output);

    if (attempt === PUSH_ATTEMPTS) {
      throw new Error(
        [hint, 'Коммит остался локально. Повторный запуск скрипта доделает пуш.']
          .filter(Boolean)
          .join('\n')
      );
    }

    console.log(`Пуш не удался${hint ? `. ${hint}` : ''} Повтор через ${PUSH_DELAY_MS / 1000} с...`);
    await sleep(PUSH_DELAY_MS);
  }
}

async function main() {
  const inside = git(['rev-parse', '--is-inside-work-tree']);

  if (inside.status !== 0 || inside.stdout.trim() !== 'true') {
    throw new Error(`Не git-репозиторий: ${ROOT}`);
  }

  const symbolicBranch = git(['symbolic-ref', '--short', 'HEAD']);
  const branch = symbolicBranch.status === 0 ? symbolicBranch.stdout.trim() : '';

  if (!branch) {
    throw new Error('Отсоединённый HEAD: переключитесь на ветку перед коммитом.');
  }

  console.log(`Репозиторий: ${ROOT}`);
  console.log(`Ветка: ${branch}`);

  mustGit(['add', '-A']);

  const hasHead = git(['rev-parse', '--verify', '--quiet', 'HEAD']).status === 0;
  let hasChanges = false;

  if (hasHead) {
    const stagedDiff = git(['diff', '--cached', '--quiet']);

    if (stagedDiff.status > 1) {
      throw new Error('Не удалось проверить проиндексированные изменения.');
    }

    hasChanges = stagedDiff.status === 1;
  } else {
    hasChanges = mustGit(['ls-files', '--cached']).stdout.trim() !== '';
  }

  if (hasChanges) {
    const stat = hasHead
      ? mustGit(['diff', '--cached', '--stat']).stdout.trim()
      : mustGit(['status', '--short']).stdout.trim();

    if (stat) {
      console.log(stat);
    }

    const message = await readMessage();

    mustGit(['commit', '-F', '-'], { input: `${message}\n` });
    console.log(`Коммит: ${mustGit(['log', '-1', '--oneline']).stdout.trim()}`);
  } else {
    console.log('Нет изменений для коммита — проверяю, есть ли что запушить.');
  }

  await push(branch);
}

try {
  await main();
} catch (error) {
  const message = error instanceof Error ? error.message : String(error);
  console.error(`\nОшибка: ${message}`);
  exit(1);
}
