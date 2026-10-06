<?php

namespace App\Modules\Shared\Database;

use BackedEnum;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Support\Fluent;
use InvalidArgumentException;

/**
 * Blueprint helpers that encode the database conventions of spec §6, so every
 * migration builds columns the same way instead of repeating the rules.
 *
 * The app talks to MySQL 8 and MariaDB through the same `mysql` driver; the two
 * places where they differ (ngram full-text parser) are handled here.
 */
final class SchemaMacros
{
    public static function register(): void
    {
        self::registerGrammarCompilers();
        self::registerColumnMacros();
        self::registerConstraintMacros();
    }

    private static function registerColumnMacros(): void
    {
        /** Rule 2: time-ordered UUID primary key stored as char(36). */
        Blueprint::macro('uuidPrimary', function (): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->uuid('id')->primary();
        });

        /** Rule 3: created_at / updated_at as datetime(6), never timestamp. */
        Blueprint::macro('standardTimestamps', function (): void {
            /** @var Blueprint $this */
            $this->datetimes(6);
        });

        /** Rule 4: soft delete for master and people tables only. */
        Blueprint::macro('standardSoftDeletes', function (): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->softDeletesDatetime('deleted_at', 6);
        });

        /**
         * Rule 5: created_by / updated_by referencing users.id.
         * Pass false only for tables that `users` itself depends on, where the
         * foreign key would be circular; those columns are still indexed.
         */
        Blueprint::macro('actorColumns', function (bool $withForeignKeys = true): void {
            foreach (['created_by', 'updated_by'] as $column) {
                if ($withForeignKeys) {
                    $this->foreignUuid($column)->nullable()->constrained('users')->restrictOnDelete();

                    continue;
                }

                $this->uuid($column)->nullable()->index();
            }
        });

        /** Rule 6: money is decimal(12,2) in INR, never float. */
        Blueprint::macro('money', function (string $column): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->decimal($column, 12, 2);
        });

        /** Rule 6: percentages are decimal(5,2). */
        Blueprint::macro('percentage', function (string $column): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->decimal($column, 5, 2);
        });

        /**
         * Rule 7: enumerations are varchar(32) plus a CHECK constraint listing
         * the values of the PHP backed enum, so code and database cannot drift.
         *
         * @param  class-string<BackedEnum>  $enumClass
         */
        Blueprint::macro('enumString', function (string $column, string $enumClass): ColumnDefinition {
            /** @var Blueprint $this */
            $this->check(SchemaMacros::enumCheckExpression($column, $enumClass), "{$this->getTable()}_{$column}_check");

            return $this->string($column, 32);
        });

        /**
         * Rebuilds the CHECK of an enumString column after cases were added
         * to its enum (the column itself is unchanged).
         *
         * @param  class-string<BackedEnum>  $enumClass
         */
        Blueprint::macro('refreshEnumCheck', function (string $column, string $enumClass): void {
            /** @var Blueprint $this */
            $name = "{$this->getTable()}_{$column}_check";
            $this->addCommand('dropCheck', ['index' => $name]);
            $this->check(SchemaMacros::enumCheckExpression($column, $enumClass), $name);
        });

        /** Hashes (SHA-256 hex) use an ascii char(64) column. */
        Blueprint::macro('sha256', function (string $column): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->char($column, 64)->charset('ascii')->collation('ascii_bin');
        });
    }

    private static function registerConstraintMacros(): void
    {
        /** A named CHECK constraint (MySQL 8.0.16+, MariaDB 10.2+). */
        Blueprint::macro('check', function (string $expression, string $name): Fluent {
            /** @var Blueprint $this */
            return $this->addCommand('check', ['index' => $name, 'expression' => $expression]);
        });

        /** CHECK that exactly one of two columns is set (spec §6 mapping table). */
        Blueprint::macro('exactlyOneOf', function (string $first, string $second): Fluent {
            /** @var Blueprint $this */
            return $this->check(
                sprintf('(`%s` is null) <> (`%s` is null)', $first, $second),
                "{$this->getTable()}_{$first}_{$second}_one_check",
            );
        });

        /**
         * Uniqueness that applies only to some rows (MySQL has no partial
         * indexes), e.g. one active agreement per franchise:
         *
         *   $table->uniqueWhere(['franchise_id'], "`status` = 'active'", 'is_active_agreement');
         *
         * Adds a stored generated flag that is 1 when the condition holds and
         * NULL otherwise, then a UNIQUE index on (columns…, flag). NULLs never
         * collide, so only rows meeting the condition are limited to one.
         *
         * The flag is numeric on purpose: MariaDB refuses indexed generated
         * columns that return another text column's value, which the spec's
         * `IF(status = 'active', franchise_id, NULL)` form needs.
         *
         * @param  list<string>  $columns
         */
        Blueprint::macro('uniqueWhere', function (array $columns, string $condition, string $flagColumn, ?string $indexName = null): ColumnDefinition {
            /** @var Blueprint $this */
            $definition = $this->boolean($flagColumn)->nullable()->storedAs("if({$condition}, 1, null)");
            $this->unique([...$columns, $flagColumn], $indexName ?? "{$this->getTable()}_{$flagColumn}_unique");

            return $definition;
        });

        /**
         * Full-text index for partial-name search. MySQL uses the ngram parser
         * (spec §3); MariaDB has no ngram parser and gets a standard index.
         */
        Blueprint::macro('searchableText', function (string $column): Fluent {
            /** @var Blueprint $this */
            return $this->addCommand('searchableText', [
                'index' => "{$this->getTable()}_{$column}_fulltext",
                'column' => $column,
            ]);
        });
    }

    private static function registerGrammarCompilers(): void
    {
        MySqlGrammar::macro('compileCheck', function (Blueprint $blueprint, Fluent $command): string {
            /** @var MySqlGrammar $this */
            return sprintf(
                'alter table %s add constraint %s check (%s)',
                $this->wrapTable($blueprint),
                $this->wrap($command->get('index')),
                $command->get('expression'),
            );
        });

        // MySQL 8.0.19+ and MariaDB both accept DROP CONSTRAINT for a CHECK.
        MySqlGrammar::macro('compileDropCheck', function (Blueprint $blueprint, Fluent $command): string {
            /** @var MySqlGrammar $this */
            return sprintf('alter table %s drop constraint %s', $this->wrapTable($blueprint), $this->wrap($command->get('index')));
        });

        MySqlGrammar::macro('compileSearchableText', function (Blueprint $blueprint, Fluent $command): string {
            /** @var MySqlGrammar $this */
            $isMaria = $this->connection instanceof MySqlConnection && $this->connection->isMaria();
            $parser = $isMaria ? '' : ' with parser ngram';

            return sprintf(
                'alter table %s add fulltext index %s (%s)%s',
                $this->wrapTable($blueprint),
                $this->wrap($command->get('index')),
                $this->wrap($command->get('column')),
                $parser,
            );
        });
    }

    /**
     * `column in ('a', 'b')` from a backed enum's values.
     *
     * @param  class-string<BackedEnum>  $enumClass
     */
    public static function enumCheckExpression(string $column, string $enumClass): string
    {
        if (! is_subclass_of($enumClass, BackedEnum::class)) {
            throw new InvalidArgumentException("{$enumClass} is not a backed enum.");
        }

        $allowedValues = array_map(
            fn (BackedEnum $case): string => "'".str_replace("'", "''", (string) $case->value)."'",
            $enumClass::cases(),
        );

        return sprintf('`%s` in (%s)', $column, implode(', ', $allowedValues));
    }
}
