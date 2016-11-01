<?php

/**
 * Contains the DataFrameCore class.
 * @package   DataFrame
 * @author    Howard Gehring <hwgehring@gmail.com>
 * @copyright 2015 Howard Gehring <hwgehring@gmail.com>
 * @license   https://github.com/HWGehring/Archon/blob/master/LICENSE BSD-3-Clause
 * @link      https://github.com/HWGehring/Archon
 * @since     0.1.0
 */

namespace Archon;

use Archon\Exceptions\DataFrameException;
use Archon\Exceptions\InvalidColumnException;
use Closure;
use Countable;
use DateTime;
use Exception;
use Iterator;
use ArrayAccess;
use PDO;
use RuntimeException;

/**
 * The DataFrameCore class acts as the implementation for the various data manipulation features of the DataFrame class.
 * @package   Archon
 * @author    Howard Gehring <hwgehring@gmail.com>
 * @copyright 2015 Howard Gehring <hwgehring@gmail.com>
 * @license   https://github.com/HWGehring/Archon/blob/master/LICENSE BSD-3-Clause
 * @link      https://github.com/HWGehring/Archon
 * @since     0.1.0
 */
abstract class DataFrameCore implements ArrayAccess, Iterator, Countable
{

    /* *****************************************************************************************************************
     *********************************************** Core Implementation ***********************************************
     ******************************************************************************************************************/

    protected $data = [];
    protected $columns = [];

    protected function __construct(array $data)
    {
        if (count($data) > 0) {
            $this->data = array_values($data);
            $this->columns = array_keys(current($data));
        }
    }

    /**
     * Returns the DataFrame's columns as an array.
     * @return array
     * @since  0.1.0
     */
    public function columns()
    {
        return $this->columns;
    }

    /**
     * Returns a specific row index of the DataFrame.
     * @param  $index
     * @return array
     * @since  0.1.0
     */
    public function getIndex($index)
    {
        return $this->data[$index];
    }

    /**
     * Applies a user-defined function to each row of the DataFrame. The parameters of the function include the row
     * being iterated over, and optionally the index. ie: apply(function($el, $ix) { ... })
     * @param  Closure $f
     * @return DataFrameCore
     * @since  0.1.0
     */
    public function apply(Closure $f)
    {
        if (count($this->columns()) > 1) {
            foreach ($this->data as $i => &$row) {
                $row = $f($row, $i);
            }
        }

        if (count($this->columns()) === 1) {
            foreach ($this->data as $i => &$row) {
                $row[key($row)] = $f($row[key($row)], $i);
            }
        }

        return $this;
    }

    /**
     * Allows SQL to be used to perform operations on the DataFrame
     *
     * Table name will always be 'dataframe'
     *
     * @param $sql
     * @param PDO $pdo
     * @return DataFrame
     * @throws DataFrameException
     */
    public function query($sql, PDO $pdo = null) {
        $sql = trim($sql);
        $query_type = trim(strtoupper(strtok($sql, ' ')));

        if ($pdo === null) {
            $pdo = new PDO('sqlite::memory:');
        }

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql_columns = implode(', ', $this->columns);
        } elseif ($driver === 'mysql') {
            $sql_columns = implode(' VARCHAR(255), ', $this->columns) . ' VARCHAR(255)';
        } else {
            throw new DataFrameException("{$driver} is not yet supported for DataFrame query.");
        }

        $pdo->exec("DROP TABLE IF EXISTS dataframe;");
        $pdo->exec("CREATE TABLE IF NOT EXISTS dataframe ({$sql_columns});");

        $df = DataFrame::fromArray($this->data);
        $df->toSQL('dataframe', $pdo);

        if ($query_type === 'SELECT') {
            $result = $pdo->query($sql, PDO::FETCH_ASSOC);
        } else {
            $pdo->exec($sql);
            $result = $pdo->query("SELECT * FROM dataframe;", PDO::FETCH_ASSOC);
        }

        $results = $result->fetchAll();

        $pdo->exec("DROP TABLE IF EXISTS dataframe;");

        return DataFrame::fromArray($results);
    }

    /**
     * Assertion that the DataFrame must have the column specified. If not then an exception is thrown.
     * @param  $columnName
     * @throws InvalidColumnException
     * @since  0.1.0
     */
    public function mustHaveColumn($columnName)
    {
        if ($this->hasColumn($columnName) === false) {
            throw new InvalidColumnException("{$columnName} doesn't exist in DataFrame");
        }
    }

    /**
     * Returns a boolean of whether the specified column exists.
     * @param  $columnName
     * @return bool
     * @since  0.1.0
     */
    public function hasColumn($columnName)
    {
        if (array_search($columnName, $this->columns) === false) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Adds a new column to the DataFrame.
     * @internal
     * @param $columnName
     * @since 0.1.0
     */
    private function addColumn($columnName)
    {
        if (!$this->hasColumn($columnName)) {
            $this->columns[] = $columnName;
        }
    }

    /**
     * Removes a column (and all associated data) from the DataFrame.
     * @param $columnName
     * @since 0.1.0
     */
    public function removeColumn($columnName)
    {
        unset($this[$columnName]);
    }

    /**
     * Allows user to "array_merge" two DataFrames so that the rows of one are appended to the rows of another.
     *
     * @param $other
     * @return $this
     */
    public function append(DataFrame $other) {
        if (count($other) <= 0) {
            return $this;
        }

        $columns = $this->columns;

        foreach ($other as $row) {
            $new_row = [];
            foreach ($columns as $column) {
                $new_row[$column] = $row[$column];
            }

            $this->data[] = $new_row;
        }

        return $this;
    }

    /**
     * Replaces all occurences within the DataFrame of regex $pattern with string $replacement
     * @param $pattern
     * @param $replacement
     */
    public function pregReplace($pattern, $replacement) {
        foreach($this->data as &$row) {
            $row = preg_replace($pattern, $replacement, $row);
        }
    }

    /**
     * Allows user to apply type default values to certain columns when necessary. This is usually utilized
     * in conjunction with a database to avoid certain invalid type defaults (ie: dates of 0000-00-00).
     *
     * ie:
     *      $df->map_types([
     *          'some_amount' => 'DECIMAL',
     *          'some_int'    => 'INT',
     *          'some_date'   => 'DATE'
     *      ], ['Y-m-d'], 'm/d/Y');
     *
     * @param array $type_map
     * @param null|string $from_date_format The date format of the input.
     * @param null|string $to_date_format The date format of the output.
     * @throws Exception
     */
    public function convertTypes(array $type_map, $from_date_format = null, $to_date_format = null) {
        foreach ($this as $i => $row) {
            foreach ($type_map as $column => $type) {
                if ($type == 'DECIMAL') {
                    $this->data[$i][$column] = $this->convertDecimal($row[$column]);
                } elseif ($type == 'INT') {
                    $this->data[$i][$column] = $this->convertInt($row[$column]);
                } elseif ($type == 'DATE') {
                    $this->data[$i][$column] = $this->convertDate($row[$column], $from_date_format, $to_date_format);
                } elseif ($type == 'CURRENCY') {
                    $this->data[$i][$column] = $this->convertCurrency($row[$column]);
                } elseif ($type == 'ACCOUNTING') {
                    $this->data[$i][$column] = $this->convertAccounting($row[$column]);
                }
            }
        }
    }

    private function convertDecimal($value) {
        $value = str_replace(['$', ',', ' '], '', $value);

        if (substr($value, 1) == '.') {
            $value = '0'.$value;
        }

        if ($value == '0' || $value == '' || $value == '-0.00') {
            return '0.00';
        }

        if (substr($value, -1) == '-') {
            $value = '-'.substr($value, 0, -1);
        }

        return $value;

    }

    private function convertInt($value) {
        if ($value === '') {
            return '0';
        }

        if (substr($value, -1) === '-') {
            $value = '-'.substr($value, 0, -1);
        }

        return str_replace(',', '', $value);
    }

    private function convertDate($value, $from_format, $to_format) {
        if ($value === '') {
            return '0001-01-01';
        }

        if (is_array($from_format)) {
            $error_parsing_date = false;
            $current_format = null;

            foreach ($from_format as $date_format) {
                $current_format = $date_format;
                $oldDateTime = DateTime::createFromFormat($date_format, $value);
                if ($oldDateTime === false) {
                    $error_parsing_date = true;
                    continue;
                } else {
                    $newDateString = $oldDateTime->format($to_format);
                    return $newDateString;
                }
            }

            if ($error_parsing_date === true) {
                throw new RuntimeException("Error parsing date string '{$value}' with date format {$current_format}");
            }

        } else {

            $oldDateTime = DateTime::createFromFormat($from_format, $value);
            if ($oldDateTime === false) {
                throw new RuntimeException("Error parsing date string '{$value}' with date format {$from_format}");
            }

            $newDateString = $oldDateTime->format($to_format);
            return $newDateString;
        }

        throw new RuntimeException("Error parsing date string: '{$value}' with date format: {$from_format}");
    }

    private function convertCurrency($value) {
        $value = explode('.', $value);
        $value[1] = $value[1] ?? '00';
        $value[0] = ($value[0] == '' or $value[0] == '-') ? '0' : $value[0];
        $value[1] = ($value[1] == '' or $value[1] == '0') ? '00' : $value[1];

        $dollars = number_format($value[0]).'.'.$value[1];

        if (substr($dollars, 0, 1) == '-') {
            $dollars = '-$'.substr($dollars, 1);
        } else {
            $dollars = '$'.$dollars;
        }

        return $dollars;
    }

    private function convertAccounting($value) {
        $value = explode('.', $value);
        $value[1] = $value[1] ?? '00';
        $value[0] = ($value[0] == '' or $value[0] == '-') ? '0' : $value[0];
        $value[1] = ($value[1] == '' or $value[1] == '0') ? '00' : $value[1];

        $dollars = number_format($value[0]) . '.' . $value[1];

        if (substr($dollars, 0, 1) == '-') {
            $dollars = '('.substr($dollars, 1).')';
        }

        return '$'.$dollars;
    }

    /* *****************************************************************************************************************
     ******************************************* ArrayAccess Implementation ********************************************
     ******************************************************************************************************************/

    /**
     * Provides isset($df['column']) functionality.
     * @internal
     * @param  mixed $columnName
     * @return bool
     * @since  0.1.0
     */
    public function offsetExists($columnName)
    {
        foreach ($this as $row) {
            if (!array_key_exists($columnName, $row)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Allows user retrieve DataFrame subsets from a two-dimensional array by
     * simply requesting an element of the instantiated DataFrame.
     *      ie: $fooDF = $df['foo'];
     * @internal
     * @param  mixed $columnName
     * @return DataFrame
     * @throws InvalidColumnException
     * @since  0.1.0
     */
    public function offsetGet($columnName)
    {
        $this->mustHaveColumn($columnName);

        $getColumn = function ($el) use ($columnName) {
            return $el[$columnName];
        };

        $data = array_map($getColumn, $this->data);

        foreach ($data as &$row) {
            $row = [$columnName => $row];
        }

        return new DataFrame($data);
    }

    /**
     * Allows user set DataFrame columns from a Closure, value, or another single-column DataFrame.
     *      ie:
     *          $df[$targetColumn] = $rightHandSide
     *          $df['bar'] = $df['foo'];
     *          $df['bar'] = $df->foo;
     *          $df['foo'] = function ($foo) { return $foo + 1; };
     *          $df['foo'] = 'bar';
     * @internal
     * @param  mixed $targetColumn
     * @param  mixed $rightHandSide
     * @throws DataFrameException
     * @since  0.1.0
     */
    public function offsetSet($targetColumn, $rightHandSide)
    {
        if ($rightHandSide instanceof DataFrame) {
            $this->offsetSetDataFrame($targetColumn, $rightHandSide);
        } else if ($rightHandSide instanceof Closure) {
            $this->offsetSetClosure($targetColumn, $rightHandSide);
        } else {
            $this->offsetSetValue($targetColumn, $rightHandSide);
        }
    }

    /**
     * Allows user set DataFrame columns from a single-column DataFrame.
     *      ie:
     *          $df['bar'] = $df['foo'];
     * @internal
     * @param  $targetColumn
     * @param  DataFrame $df
     * @throws DataFrameException
     * @since  0.1.0
     */
    private function offsetSetDataFrame($targetColumn, DataFrame $df)
    {
        if (count($df->columns()) !== 1) {
            $msg = "Can only set a new column from a DataFrame with a single ";
            $msg .= "column.";
            throw new DataFrameException($msg);
        }

        if (count($df) != count($this)) {
            $msg = "Source and target DataFrames must have identical number ";
            $msg .= "of rows.";
            throw new DataFrameException($msg);
        }

        $this->addColumn($targetColumn);

        foreach ($this as $i => $row) {
            $this->data[$i][$targetColumn] = current($df->getIndex($i));
        }
    }

    /**
     * Allows user set DataFrame columns from a Closure.
     *      ie:
     *          $df['foo'] = function ($foo) { return $foo + 1; };
     * @internal
     * @param $targetColumn
     * @param Closure $f
     * @since 0.1.0
     */
    private function offsetSetClosure($targetColumn, Closure $f)
    {
        foreach ($this as $i => $row) {
            $this->data[$i][$targetColumn] = $f($row[$targetColumn]);
        }
    }

    /**
     * Allows user set DataFrame columns from a variable.
     *      ie:
     *          $df['foo'] = 'bar';
     * @internal
     * @param $targetColumn
     * @param $value
     * @since 0.1.0
     */
    private function offsetSetValue($targetColumn, $value)
    {
        $this->addColumn($targetColumn);
        foreach ($this as $i => $row) {
            $this->data[$i][$targetColumn] = $value;
        }
    }

    /**
     * Allows user to remove columns from the DataFrame using unset.
     *      ie: unset($df['column'])
     * @param  mixed $offset
     * @throws InvalidColumnException
     * @since  0.1.0
     */
    public function offsetUnset($offset)
    {
        $this->mustHaveColumn($offset);

        foreach ($this as $i => $row) {
            unset($this->data[$i][$offset]);
        }

        if (($key = array_search($offset, $this->columns)) !== false) {
            unset($this->columns[$key]);
        }
    }

    /* *****************************************************************************************************************
     ********************************************* Iterator Implementation *********************************************
     ******************************************************************************************************************/

    private $pointer = 0;

    /**
     * Return the current element
     * @link   http://php.net/manual/en/iterator.current.php
     * @return mixed Can return any type.
     * @since  0.1.0
     */
    public function current()
    {
        return $this->data[$this->key()];
    }

    /**
     * Move forward to next element
     * @link   http://php.net/manual/en/iterator.next.php
     * @return void Any returned value is ignored.
     * @since  0.1.0
     */
    public function next()
    {
        $this->pointer++;
    }

    /**
     * Return the key of the current element
     * @link   http://php.net/manual/en/iterator.key.php
     * @return mixed scalar on success, or null on failure.
     * @since  0.1.0
     */
    public function key()
    {
        return $this->pointer;
    }

    /**
     * Checks if current position is valid
     * @link   http://php.net/manual/en/iterator.valid.php
     * @return boolean The return value will be casted to boolean and then evaluated.
     *                 Returns true on success or false on failure.
     * @since  0.1.0
     */
    public function valid()
    {
        return isset($this->data[$this->key()]);
    }

    /**
     * Rewind the Iterator to the first element
     * @link   http://php.net/manual/en/iterator.rewind.php
     * @return void Any returned value is ignored.
     * @since  0.1.0
     */
    public function rewind()
    {
        $this->pointer = 0;
    }

    /* *****************************************************************************************************************
     ******************************************** Countable Implementation *********************************************
     ******************************************************************************************************************/

    /**
     * Count elements of an object
     * @link   http://php.net/manual/en/countable.count.php
     * @return int The custom count as an integer.
     *             The return value is cast to an integer.
     * @since  0.1.0
     */
    public function count()
    {
        return count($this->data);
    }
}
