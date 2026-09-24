// PreToolUse (Edit|Write|MultiEdit): bloqueia arquivos sensíveis. exit 2 = bloqueia e explica ao Claude.
import { readFileSync, statSync, existsSync } from 'node:fs';
import path from 'node:path';

const input = JSON.parse(readFileSync(0, 'utf8') || '{}');
const file = input?.tool_input?.file_path;
if (!file) process.exit(0);

const root = process.env.CLAUDE_PROJECT_DIR || process.cwd();
const rel = path.relative(root, path.resolve(root, file)).split(path.sep).join('/');
const block = (msg) => { process.stderr.write(msg + '\n'); process.exit(2); };

if (/(^|\/)\.env(\..+)?$/.test(rel) && !rel.endsWith('.env.example')) {
  block(`Bloqueado: ${rel} contém segredos e não pode ser editado pelo agente. Peça ao usuário para alterar manualmente.`);
}
if (/^(node_modules|dist|backend\/vendor)\//.test(rel)) {
  block(`Bloqueado: ${rel} é gerado/instalado; não edite à mão.`);
}
if (/^backend\/database\/migrations\/.+\.php$/.test(rel) && existsSync(path.resolve(root, file))) {
  const st = statSync(path.resolve(root, file));
  const born = st.birthtimeMs || st.ctimeMs;
  const TWELVE_HOURS = 12 * 60 * 60 * 1000;
  if (Date.now() - born > TWELVE_HOURS) {
    block(`Bloqueado: ${rel} é uma migration existente. Crie uma nova migration (php artisan make:migration) em vez de editá-la.`);
  }
}
process.exit(0);
