<?php

declare(strict_types=1);

use App\Support\DatabaseSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-45: no MariaDB/MySQL a collation padrão ignora maiúscula e acento ("ana@x.com" = "ANA@X.COM", sessão "AbC" =
 * "abc"). O Postgres compara exato. Tokens, chaves e e-mails passam para utf8mb4_bin, que compara exato.
 *
 * Só age no MariaDB/MySQL: no Postgres e no SQLite (testes de caracterização) não faz nada. Mantém tipo, tamanho
 * e NULL/NOT NULL de cada coluna (lidos do information_schema); nenhuma delas tem valor padrão.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->convert(DatabaseSettings::BINARY_COLLATION);
    }

    public function down(): void
    {
        $this->convert((string) DB::connection()->getConfig('collation'));
    }

    private function convert(string $collation): void
    {
        if (! in_array(DB::getDriverName(), ['mariadb', 'mysql'], true)) {
            return;
        }

        foreach (DatabaseSettings::BINARY_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $definition = DB::selectOne(
                    'SELECT COLUMN_TYPE AS type, IS_NULLABLE AS nullable FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [DB::getTablePrefix().$table, $column]
                );

                if ($definition === null) {
                    throw new RuntimeException("Coluna {$table}.{$column} não existe.");
                }

                DB::statement(sprintf(
                    'ALTER TABLE `%s` MODIFY `%s` %s CHARACTER SET utf8mb4 COLLATE %s %s',
                    DB::getTablePrefix().$table,
                    $column,
                    $definition->type,
                    $collation,
                    $definition->nullable === 'YES' ? 'NULL' : 'NOT NULL',
                ));
            }
        }
    }
};
