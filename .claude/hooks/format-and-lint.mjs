// PostToolUse (Edit|Write|MultiEdit): formata PHP com Pint e roda oxlint em JS/JSX.
// Ferramenta ausente = ignora em silêncio. Erro de lint = exit 2 (o Claude vê e corrige).
import { readFileSync, existsSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import path from 'node:path';

const input = JSON.parse(readFileSync(0, 'utf8') || '{}');
const file = input?.tool_input?.file_path;
if (!file) process.exit(0);

const root = process.env.CLAUDE_PROJECT_DIR || process.cwd();
const abs = path.resolve(root, file);
const win = process.platform === 'win32';

// Pint só no arquivo editado (nunca --dirty nem o backend inteiro). Como o backend está 100%
// formatado, o diff fica restrito às linhas editadas. Argumentos em array, sem shell: caminhos
// com espaço funcionam, inclusive no Git Bash do Windows. Pint com erro = aviso (exit 2).
if (abs.endsWith('.php')) {
  const backend = path.join(root, 'backend');
  const pint = path.join(backend, 'vendor', 'bin', 'pint');
  if (!existsSync(pint)) process.exit(0);
  const r = spawnSync('php', [pint, abs], { cwd: backend, encoding: 'utf8' });
  if (r.error || r.status !== 0) {
    const detail = r.error ? r.error.message : `${r.stdout}${r.stderr}`.trim();
    process.stderr.write(`Pint falhou em ${path.relative(root, abs)} (status ${r.status ?? 'n/d'}); o arquivo não foi formatado.\n${detail}\n`);
    process.exit(2);
  }
  process.exit(0);
}

if (/\.(js|jsx|mjs)$/.test(abs) && !abs.includes(`${path.sep}.claude${path.sep}`)) {
  const bin = path.join(root, 'node_modules', '.bin', win ? 'oxlint.cmd' : 'oxlint');
  if (!existsSync(bin)) process.exit(0);
  const r = spawnSync(bin, [abs], { cwd: root, encoding: 'utf8', shell: win });
  if (r.status && r.status !== 0) {
    process.stderr.write(`oxlint encontrou erros em ${path.relative(root, abs)}:\n${r.stdout}${r.stderr}\nCorrija antes de continuar.\n`);
    process.exit(2);
  }
}
process.exit(0);
