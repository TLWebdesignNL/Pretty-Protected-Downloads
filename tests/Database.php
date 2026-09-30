<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * A database for the tests: the part of Joomla's query builder the plugin uses,
 * rendered to real SQL and run on an in-memory SQLite database with the columns of
 * the Joomla tables the plugin reads. So the queries the helper builds, with their
 * joins and bound values, are what is tested, not a description of them.
 */

namespace Joomla\Database {
    interface DatabaseInterface
    {
    }

    final class ParameterType
    {
        public const INTEGER = 'int';
        public const STRING  = 'string';
    }
}

namespace {
    use Joomla\Database\DatabaseInterface;
    use Joomla\Database\ParameterType;

    /**
     * A query: collects its parts, and renders them as SQL with named parameters.
     */
    final class TestQuery
    {
        private array $select = [];
        private string $from  = '';
        private string $update = '';
        private array $set    = [];
        private array $joins  = [];
        private array $where  = [];
        private array $binds  = [];
        private int $arrays   = 0;

        public function select($columns): self
        {
            array_push($this->select, ...(array) $columns);

            return $this;
        }

        public function from(string $table): self
        {
            $this->from = $table;

            return $this;
        }

        public function update(string $table): self
        {
            $this->update = $table;

            return $this;
        }

        public function set(string $assignment): self
        {
            $this->set[] = $assignment;

            return $this;
        }

        public function join(string $type, string $table, ?string $condition = null): self
        {
            $this->joins[] = strtoupper($type) . ' JOIN ' . $table . ($condition !== null ? ' ON ' . $condition : '');

            return $this;
        }

        public function where(string $condition): self
        {
            $this->where[] = $condition;

            return $this;
        }

        public function whereIn(string $key, array $values, $type = ParameterType::INTEGER): self
        {
            $names = [];

            foreach (array_values($values) as $value) {
                $name           = ':array' . $this->arrays++;
                $names[]        = $name;
                $this->binds[$name] = ['value' => $value, 'type' => $type];
            }

            return $this->where($key . ' IN (' . implode(', ', $names) . ')');
        }

        public function bind(string $key, &$value, $type = ParameterType::STRING): self
        {
            $this->binds[$key] = ['value' => &$value, 'type' => $type];

            return $this;
        }

        /**
         * @return  array{string, array}  The SQL and its parameters.
         */
        public function compile(): array
        {
            $sql = $this->update !== ''
                ? 'UPDATE ' . $this->update . ' SET ' . implode(', ', $this->set)
                : 'SELECT ' . implode(', ', $this->select) . ' FROM ' . $this->from . ($this->joins ? ' ' . implode(' ', $this->joins) : '');

            if ($this->where !== []) {
                $sql .= ' WHERE ' . implode(' AND ', array_map(static fn (string $c): string => '(' . $c . ')', $this->where));
            }

            return [str_replace('#__', 'j_', $sql), $this->binds];
        }
    }

    /**
     * The database: DatabaseInterface as far as the plugin uses it.
     */
    final class TestDatabase implements DatabaseInterface
    {
        public \PDO $pdo;
        private ?TestQuery $query = null;
        private int $offset       = 0;
        private int $limit        = 0;

        public function __construct()
        {
            $this->pdo = new \PDO('sqlite::memory:');
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec(<<<'SQL'
                CREATE TABLE j_content (id INTEGER PRIMARY KEY, catid INTEGER, state INTEGER, access INTEGER, created_by INTEGER, publish_up TEXT, publish_down TEXT);
                CREATE TABLE j_contact_details (id INTEGER PRIMARY KEY, catid INTEGER, published INTEGER, access INTEGER, created_by INTEGER, publish_up TEXT, publish_down TEXT);
                CREATE TABLE j_categories (id INTEGER PRIMARY KEY, extension TEXT, published INTEGER, access INTEGER, created_user_id INTEGER);
                CREATE TABLE j_users (id INTEGER PRIMARY KEY, block INTEGER);
                CREATE TABLE j_fields (id INTEGER PRIMARY KEY, context TEXT, group_id INTEGER, name TEXT, type TEXT, params TEXT, fieldparams TEXT, access INTEGER, state INTEGER);
                CREATE TABLE j_fields_groups (id INTEGER PRIMARY KEY, access INTEGER, state INTEGER);
                CREATE TABLE j_fields_values (field_id INTEGER, item_id TEXT, value TEXT);
            SQL);
        }

        /**
         * Insert a row.
         *
         * @param   string  $table  The table, without prefix.
         * @param   array   $row    Column => value.
         *
         * @return  void
         */
        public function insert(string $table, array $row): void
        {
            $columns = array_keys($row);
            $this->pdo->prepare(
                'INSERT INTO j_' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, \count($columns), '?')) . ')'
            )->execute(array_values($row));
        }

        public function createQuery(): TestQuery
        {
            return new TestQuery();
        }

        public function quoteName($name, $as = null)
        {
            if (\is_array($name)) {
                return array_map(fn ($n, $i) => $this->quoteName($n, \is_array($as) ? ($as[$i] ?? null) : null), $name, array_keys($name));
            }

            $quoted = implode('.', array_map(static fn (string $part): string => '`' . $part . '`', explode('.', $name)));

            return $as !== null ? $quoted . ' AS `' . $as . '`' : $quoted;
        }

        public function setQuery($query, $offset = 0, $limit = 0): self
        {
            $this->query  = $query;
            $this->offset = (int) $offset;
            $this->limit  = (int) $limit;

            return $this;
        }

        public function loadObject(): ?object
        {
            $row = $this->run()->fetch(\PDO::FETCH_OBJ);

            return $row === false ? null : $row;
        }

        public function loadResult()
        {
            $value = $this->run()->fetchColumn();

            return $value === false ? null : $value;
        }

        public function loadColumn(): array
        {
            return $this->run()->fetchAll(\PDO::FETCH_COLUMN);
        }

        public function loadObjectList(): array
        {
            return $this->run()->fetchAll(\PDO::FETCH_OBJ);
        }

        public function execute(): bool
        {
            $this->run();

            return true;
        }

        private function run(): \PDOStatement
        {
            [$sql, $binds] = $this->query->compile();

            if ($this->limit > 0) {
                $sql .= ' LIMIT ' . $this->limit . ' OFFSET ' . $this->offset;
            }

            $statement = $this->pdo->prepare($sql);

            foreach ($binds as $name => $bind) {
                $statement->bindValue($name, $bind['value'], $bind['type'] === ParameterType::INTEGER ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }

            $statement->execute();

            return $statement;
        }
    }
}
