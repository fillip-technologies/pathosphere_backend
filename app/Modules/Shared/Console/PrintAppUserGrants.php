<?php

namespace App\Modules\Shared\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prints the GRANT statements for the application's database user (spec §4.5,
 * §10.8): data rights only, no DDL, and append-only tables limited to SELECT
 * and INSERT. Migrations run under a separate user.
 *
 * MySQL cannot subtract table rights from a database-wide grant, so grants are
 * issued per table. Re-run after each deploy that adds tables.
 */
final class PrintAppUserGrants extends Command
{
    protected $signature = 'db:app-user-grants {user : Database user the app connects as} {--host=localhost : Host part of the user}';

    protected $description = 'Print least-privilege GRANT statements for the application database user';

    /** Tables the app may only read and append to. */
    private const APPEND_ONLY_TABLES = ['audit_logs', 'record_access_logs', 'partner_ledger'];

    public function handle(): int
    {
        $database = DB::connection()->getDatabaseName();
        $account = sprintf("'%s'@'%s'", $this->argument('user'), $this->option('host'));

        $tables = array_column(
            DB::select('select table_name as name from information_schema.tables where table_schema = ? and table_type = ? order by table_name', [$database, 'BASE TABLE']),
            'name',
        );

        foreach ($tables as $table) {
            $privileges = in_array($table, self::APPEND_ONLY_TABLES, true)
                ? 'SELECT, INSERT'
                : 'SELECT, INSERT, UPDATE, DELETE';

            $this->line(sprintf('GRANT %s ON `%s`.`%s` TO %s;', $privileges, $database, $table, $account));
        }

        return self::SUCCESS;
    }
}
