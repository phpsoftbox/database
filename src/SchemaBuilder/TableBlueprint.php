<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\SchemaBuilder;

use PhpSoftBox\Database\Exception\ConfigurationException;

/**
 * "Чертёж" таблицы, который заполняется в коллбэке миграции.
 *
 * Это аналог твоего DbCompiler в примере.
 */
final class TableBlueprint
{
    /**
     * @var array<string, ColumnBlueprint>
     */
    private array $columns = [];

    /**
     * @param list<string> $primaryKeyColumns
     */
    private array $primaryKeyColumns = [];

    // Table modifiers (не все поддерживаются всеми драйверами)
    public ?string $charset = null;
    public ?string $collation = null;
    public ?string $comment = null;
    public ?string $engine = null;
    public bool $temporary = false;

    /**
     * @var list<IndexBlueprint>
     */
    private array $indexes = [];

    public function __construct(
        public readonly string $table,
    ) {
    }

    public function column(string $name): ColumnBlueprint
    {
        $name = trim($name);
        if ($name === '') {
            throw new ConfigurationException('Column name must be non-empty.');
        }

        $col = $this->columns[$name] ?? null;
        if ($col === null) {
            // По умолчанию TEXT, чтобы можно было модернизировать колонку позже.
            $col = new ColumnBlueprint($name, 'text');
            $this->columns[$name] = $col;
        }

        return $col;
    }

    public function id(string $name = 'id'): ColumnBlueprint
    {
        $col = new ColumnBlueprint($name, 'id');
        $col->nullable = false;
        $this->columns[$name] = $col;
        $this->primaryKeyColumns = [$name];
        return $col;
    }

    public function integer(string $name): ColumnBlueprint
    {
        $col = new ColumnBlueprint($name, 'integer');
        $this->columns[$name] = $col;
        return $col;
    }

    public function boolean(string $name): ColumnBlueprint
    {
        $col = new ColumnBlueprint($name, 'boolean');
        $this->columns[$name] = $col;
        return $col;
    }

    public function json(string $name): ColumnBlueprint
    {
        $col = new ColumnBlueprint($name, 'json');
        $this->columns[$name] = $col;
        return $col;
    }

    public function text(string $name): ColumnBlueprint
    {
        $col = new ColumnBlueprint($name, 'text');
        $this->columns[$name] = $col;
        return $col;
    }

    public function string(string $name, int $length = 255): ColumnBlueprint
    {
        $col = new ColumnBlueprint($name, 'string');
        $col->length = $length;
        $this->columns[$name] = $col;
        return $col;
    }

    public function datetime(string $name): ColumnBlueprint
    {
        $col = new ColumnBlueprint($name, 'datetime');
        $this->columns[$name] = $col;
        return $col;
    }

    public function date(string $name): ColumnBlueprint
    {
        $col = new ColumnBlueprint($name, 'date');
        $this->columns[$name] = $col;
        return $col;
    }

    public function time(string $name): ColumnBlueprint
    {
        $col = new ColumnBlueprint($name, 'time');
        $this->columns[$name] = $col;
        return $col;
    }

    public function timestamp(string $name): ColumnBlueprint
    {
        $col = new ColumnBlueprint($name, 'timestamp');
        $this->columns[$name] = $col;
        return $col;
    }

    /**
     * @return list<ColumnBlueprint>
     */
    public function columns(): array
    {
        // важно сохранить порядок добавления: array_values по insertion order PHP.
        return array_values($this->columns);
    }

    /**
     * @return list<string>
     */
    public function primaryKeyColumns(): array
    {
        return $this->primaryKeyColumns;
    }

    /**
     * @param non-empty-list<string> $columns
     */
    public function index(array $columns, ?string $name = null): self
    {
        $columns = array_values(array_filter(array_map('trim', $columns), static fn (string $c): bool => $c !== ''));
        if ($columns === []) {
            throw new ConfigurationException('Index must contain at least one column.');
        }

        $this->indexes[] = new IndexBlueprint($columns, $name, false);
        return $this;
    }

    /**
     * @param non-empty-list<string> $columns
     */
    public function unique(array $columns, ?string $name = null): self
    {
        $columns = array_values(array_filter(array_map('trim', $columns), static fn (string $c): bool => $c !== ''));
        if ($columns === []) {
            throw new ConfigurationException('Unique index must contain at least one column.');
        }

        $this->indexes[] = new IndexBlueprint($columns, $name, true);
        return $this;
    }

    /**
     * @return list<IndexBlueprint>
     */
    public function indexes(): array
    {
        return $this->indexes;
    }

    public function charset(string $charset): self
    {
        $charset = trim($charset);
        if ($charset !== '') {
            $this->charset = $charset;
        }
        return $this;
    }

    public function collation(string $collation): self
    {
        $collation = trim($collation);
        if ($collation !== '') {
            $this->collation = $collation;
        }
        return $this;
    }

    public function comment(string $comment): self
    {
        $comment = trim($comment);
        if ($comment !== '') {
            $this->comment = $comment;
        }
        return $this;
    }

    public function engine(string $engine): self
    {
        $engine = trim($engine);
        if ($engine !== '') {
            $this->engine = $engine;
        }
        return $this;
    }

    public function temporary(bool $value = true): self
    {
        $this->temporary = $value;
        return $this;
    }
}
