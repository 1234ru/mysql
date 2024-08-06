<?php

namespace One234ru;

class MySQL
{
    private \mysqli $mysqli;

    /**
     * @param $auth = [
     *  'host' => 'localhost|.|ip address',
     *  'user' => '',
     *  'password' => '',
     *  'database' => '',
     *  'port' => int,
     * ]
     * @param $options = [
     *  'on_connection_error' => string|callable
     * ]
     */
    public function __construct($auth)
    {
        try {
            $this->mysqli = @new \mysqli(
                $auth['host'] ?? null,
                $auth['user'] ?? null,
                $auth['password'] ?? null,
                $auth['port'] ?? null,
                $auth['socket'] ?? null
            );
        } catch (\mysqli_sql_exception $exception) {
            $msg = self::buildErrorMessage(
                $exception,
                "Error when connecting to database server!"
            );
            trigger_error($msg, E_USER_ERROR);
        }
        try {
            $this->mysqli->select_db($auth['database']);
        } catch (\mysqli_sql_exception $exception) {
            $msg = self::buildErrorMessage($exception);
            trigger_error($msg, E_USER_ERROR);
        }
    }

    private static function ifTextPlainHeaderWasSent() :bool
    {
        foreach (headers_list() as $header) {
            if (str_contains($header, 'text/plain')) {
                $sent = true;
                break;
            }
        }
        return $sent ?? false;
    }

    private static function buildErrorMessage(
        \mysqli_sql_exception $exception,
        $seed = 'Database error'
    ) :string {
        $msg = "\n"
            . ($seed ? "$seed\n" : "")
            . $exception->getCode()
            . " (" . $exception->getSqlState() . "): "
            . $exception->getMessage() . "\n"
            . "Trace:\n"
            . $exception->getTraceAsString();
        if (isset($_SERVER['REQUEST_URI'])) {
            http_response_code(500);
            if (!self::ifTextPlainHeaderWasSent()) {
                $msg = nl2br($msg);
            }
        }
        return $msg;
    }

    private function buildWarningMessage(
        \mysqli_warning $warning,
        $seed = 'Database warning'
    ) :string {
        $msg = "\n"
            . ($seed ? "$seed\n" : "")
            . $warning->errno
            . " (" . $warning->sqlstate . "): "
            . $warning->message . "\n"
            . "Trace:\n"
            ;
//            . print_r(debug_backtrace(), 1);
        if (isset($_SERVER['REQUEST_URI'])) {
            http_response_code(500);
            if (!self::ifTextPlainHeaderWasSent()) {
                $msg = nl2br($msg);
            }
        }
        return $msg;
    }


    public function q(
        $sql_string,
        $substitutions = [],
        $error_level = \E_USER_WARNING
    ) :\mysqli_result|bool {
        if ($substitutions) {
            $sql_string = self::substitute
                ($sql_string, $substitutions);
        }
        try {
            $result = $this->mysqli->query($sql_string);
            $this->reportWarnings($sql_string);
            return $result;
        } catch (\mysqli_sql_exception $exception) {
            $seed = "Database error on query:\n\n"
                . $sql_string . "\n";
            $msg = self::buildErrorMessage($exception, $seed);
            trigger_error($msg, $error_level);
            return false;
        }
    }

    public function substitute(
        string $sql_string,
        array $substitutions
    ) :string {
        return preg_replace_callback(
            '/:(\w+)/',
            function ($matches) use ($substitutions) {
                $key = $matches[1];
                $value = $substitutions[$key] ?? null;
                return $this->prepareValueForSQLstring($value);
            },
            $sql_string
        );
    }

    private function prepareValueForSQLstring(
        $value,
        $convert_to_json = false
    ) :string
    {
        if (!$convert_to_json) {
            if (is_array($value)) {
                $escaped = implode(
                    ',',
                    array_map([$this, __FUNCTION__], $value)
                );
                // В качестве массивов могут быть не только JSON-поля,
                // но и множество значений фильтра для IN,
                // поэтому JSON "ловим" отдельно:
                //  - при записи - в mysql_write_row()
                //  - при чтении - в ORM
            } else {
                if (
                    is_object($value)
                    and
                    (
                        get_class($value) === 'DateTime'
                        or
                        is_subclass_of($value, 'DateTime')
                    )
                ) {
                    $value = $value->format('Y-m-d H:i:s');
                }
                $escaped = $this->escapeScalarValue($value);
            }
        } else {
            $json_string = json_encode($value, JSON_UNESCAPED_UNICODE);
            $escaped = $this->escapeScalarValue($json_string);
        }
        return $escaped;
    }

    /** Keeps value type. */
    private function escapeScalarValue(int|string|bool|null $value) :string
    {
        if (is_string($value)) {
            // Через объект текущего соединения
            // получается его кодировка,
            // поэтому метод не статический.
            $escaped = "'"
                . $this->mysqli->real_escape_string($value)
                . "'";
        } elseif (is_numeric($value)) {
            $escaped = strval($value);
        } elseif (is_null($value)) {
            $escaped = 'NULL';
        } else {
            $escaped = strval(intval($value));
        }
        return $escaped;
    }

    private function reportWarnings(string $sql_string)
    {
        $seed = "Database warning on query:\n\n"
            . $sql_string . "\n";
        while ($warning = $this->mysqli->get_warnings()) {
            $msg = self::buildWarningMessage(
                $warning,
                $seed
            );
            trigger_error($msg, E_USER_NOTICE);
        }
    }

    public function getTable(
        string $sql_string,
        ?array $substitutions = [],
        ?string $column_for_keys = '',
    ) :array {
        $result = $this->q($sql_string, $substitutions);
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                if (!$column_for_keys) {
                    $rows[] = $row;
                } else {
                    $key = $row[$column_for_keys];
                    $rows[$key] = $row;
                }
            }
            $result->close();
        }
        return $rows ?? [];
    }

    public function getColumn(
        string $sql_string,
        ?array $substitutions = []
    ) :array {
        $rows = $this->getTable($sql_string, $substitutions);
        foreach ($rows as $row) {
            $column[] = reset($row);
        }
        return $column ?? [];
    }

    public function getKeyValueColumn(
        string $sql_string,
        ?array $substitutions = []
    ) :array {
        $rows = $this->getTable($sql_string, '', $substitutions);
        foreach ($rows as $row) {
            $row = array_values($row);
            $key = $row[0];
            $value = $row[1];
            $column[$key] = $value;
        }
        return $column ?? [];
    }
    public function getRow(
        string $sql_string,
        ?array $substitutions = []
    ) :array {
        $rows = $this->getTable($sql_string, $substitutions);
        $row = reset($rows);
        return $row ?: [];
    }

    public function getCell(
        string $sql_string,
        ?array $substitutions = []
    ) {
        $row = $this->getRow($sql_string, $substitutions);
        return reset($row);
    }

    /** Returns affected rows:
     *  * 1 on insert
     *  * 0 on ignore
     *  * -1 on error
     * @link https://www.php.net/manual/ru/mysqli-result.num-rows.php
     */
    public function insertRow(
        string $table_name,
        array $row,
        int $error_level = \E_USER_WARNING,
        bool $ignore = false
    ) :int {
        $sql_string = "INSERT "
            . ($ignore ? "IGNORE " : "")
            . "INTO " . $this->quoteAndEscape($table_name) . " ";
        $sql_string .= ($row ?? false)
            ? ("SET " . $this->assignValues($row))
            : "VALUES ()";
        $this->q($sql_string, [], $error_level);
        return $this->mysqli->affected_rows;
    }

    public function insertRowAndReturnId(
        string $table_name,
        array $row,
        int $error_level = \E_USER_WARNING,
        bool $ignore = false
    ) :int {
        $affected_rows = $this->insertRow(...func_get_args());
        if ($affected_rows > 0) {
            return $this->mysqli->insert_id;
        } else {
            return $affected_rows; // 0 при IGNORE, -1 при ошибке
        }
    }

    public function updateRowById(
        string $table_name,
        array $data_to_update,
        int|string $id_value,
        string $id_column_name = 'id',
        int $error_level = \E_USER_WARNING
    )
    {
        return $this->protoUpdate(
            $table_name,
            $data_to_update,
            [ $id_column_name => $id_value ],
            $error_level
        );
    }

    private function protoUpdate(
        string $table_name,
        array $data_to_update,
        array $unique_key_values = [],
        int $error_level = \E_USER_WARNING
    ) :int {
        $sql_string = "UPDATE " . $this->quoteAndEscape($table_name)
            . " SET "
            . $this->assignValues($data_to_update);
        if ($unique_key_values) {
            $sql_string .= " WHERE "
                . $this->assignValues($unique_key_values, " AND ");
        }
        $this->q($sql_string, [], $error_level);
        return $this->mysqli->affected_rows;
    }


    /**
     * $key, $value -> "`$key` = '$value'"
     *
     * Заменяет пару ключ-значение на SQL-выражение для записи.
     * Варианты:
     *  - 'поле', не массив - обычный случай
     *  - 'поле', массив - JSON-поле, переписываем полностью
     *  - 'поле.ключ', что-то - обновляем ключ у JSON-поля
     */
    private function assignment(
        string $key,
        string|int|float|bool|array|null|object $value
    ) :string {
        if (!str_contains($key, '.')) {
            $column_name = $key;
            $is_json = is_array($value);
            $column_value = $this->prepareValueForSQLstring
                ($value, $is_json   );
            $sql_string = "$column_name = $column_value";
        } else {
            // 'one.two.2.three' превратится в '$.one.two[2].three
            $tmp = explode('.', $key);
            $column_name = $tmp[0];
            $rest = '.' . $tmp[1]; // точку возвращаем
            $path = preg_replace_callback(
                '/\.(\w+)/',
                function ($matches) {
                    return (is_numeric($matches[1]))
                        ? '[' . $matches[1] . ']'
                        : '.' . $matches[1] ;
                },
                $rest
            );
            // Из JSON-пути на всякий случай исключаем
            // все лишние символы.
            $path = preg_replace('/[^\w\.\[\]]/', '', $path);

            // Все массивы вставляем как ассоциативные,
            // т.к. PHP все равно, а для JSON важно.
            // Добавляем IFNULL: JSON_SET(NULL, ...) всегда даст NULL.
            // При вставке подмассива необходимо добавить JSON_EXTRACT()
            // (или JSON_MERGE с пустым объектом);
            // в противном случае подмассив вставится
            // не как объект по структуре, а как JSON-строка
            // (с экранированными внутри кавычками)
            $column_name_quoted = $this->quoteAndEscape($column_name);
            $sql_string = $column_name_quoted
                . "JSON_SET(IFNULL(`$column_name_quoted`, '{}'), '\$$path', ";
            $sql_string .= (!is_array($value))
                ? $this->prepareValueForSQLstring($value)
                : "JSON_EXTRACT(" . $this->prepareValueForSQLstring($value, true) . ", '$')";
            // "JSON_MERGE('{}', " . mysql_escape(json_encode($value, JSON_UNESCAPED_UNICODE)) . ")";
            $sql_string .= ")";
        }
        return $sql_string;
    }

    private function quoteAndEscape(
        string $db_object_identifier
    ) :string {
        return "`"
            . $this->mysqli->real_escape_string($db_object_identifier)
            . "`";
    }

    /**
     * Превращает ассоциативный массив в SET a = 1, b = 2 ...
     */
    private function assignValues(
        array $keys_to_values,
        ?string $implode_by = ", "
    ) :string {
        foreach ($keys_to_values as $key => $value) {
            $assignments[] = $this->assignment($key, $value);
        }
        $sql_string = implode(
            $implode_by,
            $assignments ?? []
        );
        return $sql_string;
    }

    /**
     * @param string[] $unique_keys
     * @param $data = [
     *     'column_name' => 'value',
     * ]
     * @return = [
     *     'new_id' => int|null,
     *     'affected_rows' => int,
     * ]
     */
    public function insertOnDuplicateKeyUpdate(
        string $table_name,
               $data,
        array|string $unique_keys = [ 'id' ],
        int $error_level = \E_USER_WARNING,
    )
    {
        $to_update = array_diff_key(
            $data,
            array_fill_keys(
                is_array($unique_keys) ? $unique_keys : [ $unique_keys ],
                true
            )
        );
        $sql = "INSERT INTO $table_name SET\n"
            . $this->assignValues($data) . "\n"
            . "ON DUPLICATE KEY UPDATE\n"
            . $this->assignValues($to_update)
            ;
        $this->q($sql, [], $error_level);
        return [
            'new_id' => $this->mysqli->insert_id,
            'affected_rows' => $this->mysqli->affected_rows,
        ];
    }

}