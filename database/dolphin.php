<?php

/*
  dolphin v1.0
  Copyright: (c) 2017 Anuv Gupta
  File: dolphin.php (dolphin master)
  Source: [https://github.com/anuvgupta/dolphin]
  License: MIT [https://github.com/anuvgupta/dolphin/blob/master/LICENSE.md]
*/

class Dolphin {
    // attributes
    protected $database;
    protected $dbinfo;
    protected $errors;
    // constructor
    public function __construct($dbinfo) {
        if (!extension_loaded('mysqli'))
            return $this->fail("MySQLi extension is not loaded");
        $this->dbinfo = $dbinfo;
        $this->errors = [];
    }

    public function __destruct() {
        $this->disconnect();
    }

    // PUBLIC METHODS

    // method for connecting to mysql database
    public function connect() {
        $this->database = new mysqli(
            $this->dbinfo['host'],
            $this->dbinfo['user'],
            $this->dbinfo['pass'],
            $this->dbinfo['name']
        );
        if ($this->database->connect_errno > 0)
            return $this->fail("Could not connect to database: [{$this->database->connect_error}]");
    }

    // method for disconnecting from mysql database
    public function disconnect() {
        if (isset($this->database))
            @$this->database->close();
    }

    // method for setting keys with values
    public function set($table, $child, $data = null) {
        // sanitize input/error handle
        $db = $this->database;
        if (!is_string($table))
            return $this->fail("Function set requires first parameter (table name) to be of type string");
        $table = $db->real_escape_string($table);
        if (!is_string($child))
            return $this->fail("Function set requires second parameter (child ID) to be of type string");
        $child = $db->real_escape_string($child);
        if (!is_array($data))
            $this->warn("Function set prefers third parameter (data) to be of type array");
        $nullData = ($data == null || !is_array($data) || count($data) <= 0);

        // create table with id column if it does not exist
        if (($sql = $db->prepare("CREATE TABLE IF NOT EXISTS `$table` (id varchar(255))")) === false)
            return $this->fail("Could not prepare statement [$db->error]");
        if ($sql->execute() === false)
            return $this->fail("Could not run query [$sql->error]");

        // check if each column exists
        $updates = '';
        $typeUpdates = '';
        if (!$nullData) {
            $newColumns = '';
            foreach ($data as $attribute => $value) {
                $attribute = $db->real_escape_string($attribute);
                $type = 'varchar(255)';
                if (is_array($value)) {
                    if (!isset($value['type']))
                        return $this->fail('Invalid value type format');
                    else $type = $value['type'];
                    if (!isset($value['val']))
                        return $this->fail('Invalid value val format');
                    // else $value = $db->real_escape_string($value['val']);
                    $data[$attribute] = $value['val'];
                }
                // if (is_string($value))
                //     $data[$attribute] = $db->real_escape_string($value);
                // else return $this->fail('Invalid value format');
                if (!$sql = $db->prepare("SHOW COLUMNS FROM `$table` LIKE '$attribute'"))
                    return $this->fail("Could not prepare statement [$db->error]");
                if (!$sql->execute())
                    return $this->fail("Could not run query [$sql->error]");
                $result = $sql->get_result();
                $num_rows = $result->num_rows;
                $result->free();
                // prepare to add column if not exists
                if ($num_rows <= 0) $newColumns .= "ADD COLUMN `$attribute` $type, ";
                // prepare update attributes to use later
                $updates .= ",$attribute=?";
                if (is_int($value)) $typeUpdates .= 'i';
                elseif (is_double($value) || is_float($value)) $typeUpdates .= 'd';
                else $typeUpdates .= 's';
            }
            // add missing columns to table
            if (strlen($newColumns) > 1) {
                if (!$sql = $db->prepare("ALTER TABLE `$table` " . substr($newColumns, 0, strlen($newColumns) - 2)))
                    return $this->fail("Could not prepare statement [$db->error]");
                if (!$sql->execute())
                    return $this->fail("Could not run query [$sql->error]");
            }
        }

        // check if entry exists
        if (($sql = $db->prepare("SELECT * FROM `$table` WHERE id=?")) === false)
            return $this->fail("Could not prepare statement [$db->error]");
        $sql->bind_param('s', $child);
        if ($sql->execute() === false)
            return $this->fail("Could not run query [$sql->error]");
        $result = $sql->get_result();
        $num_rows = $result->num_rows;
        $result->free();
        // if entry does not exist, create new entry
        if ($num_rows <= 0) {
            if (($sql = $db->prepare("INSERT INTO `$table` (id) VALUES (?)")) === false)
                return $this->fail("Could not prepare statement [$db->error]");
            $sql->bind_param('s', $child);
            if ($sql->execute() === false)
                return $this->fail("Could not run query [$sql->error]");
        }

        // add values to table if given
        if (!$nullData && strlen($updates) > 0) {
            $updates = substr($updates, 1);
            $types = $typeUpdates . 's';
            $bind_params = array_merge([$types], array_values($data), [$child]);
            for ($i = 0; $i < count($bind_params); $i++)
                $bind_params[$i] = &$bind_params[$i];
            if (($sql = $db->prepare("UPDATE `$table` SET $updates WHERE id=?")) === false)
                return $this->fail("Could not prepare statement [$db->error]");
            call_user_func_array([$sql, 'bind_param'], $bind_params);
            if ($sql->execute() === false)
                return $this->fail("Could not run query [$sql->error]");
        }

        return true;
    }

    // method for adding new entries with new IDs
    public function push($table, $data = null, $length = 10) {
        $db = $this->database;
        $id = '';
        $query = "SHOW TABLES LIKE '$table'";
        if (!$sql = $db->prepare($query))
            return $this->fail("Could not prepare statement [$db->error]");
        if (!$sql->execute())
            return $this->fail("Could not run query [$sql->error]");
        $result = $sql->get_result();
        $num_rows = $result->num_rows;
        $result->free();
        if ($num_rows > 0) {
            while (true) {
                $id = $this->id($length);
                $query = "SELECT id FROM `$table` WHERE id=?";
                if (!$sql = $db->prepare($query))
                    return $this->fail("Could not prepare statement [$db->error]");
                $sql->bind_param('s', $id);
                if (!$sql->execute())
                    return $this->fail("Could not run query [$sql->error]");
                $result = $sql->get_result();
                $num_rows = $result->num_rows;
                if ($num_rows <= 0) break;
            }
        } else $id = $this->id($length);
        if ($this->set($table, $id, $data) === true)
            return $id;
        else return false;
    }

    // method for getting values from keys
    public function get($table, $child = null, $data = null) {
        // sanitize input/error handle
        $db = $this->database;
        if (!is_string($table))
            return $this->fail("Function get requires first parameter (table name) to be of type string");
        $table = $db->real_escape_string($table);
        if (!is_string($child))
            $this->warn("Function get prefers second parameter (child ID) to be of type string");
        else $child = $db->real_escape_string($child);
        if (is_string($data))
            $data = [$data];
        if (!is_array($data))
            $this->warn("Function get prefers third parameter (data) to be of type array");
        $nullChild = ($child == null || (is_string($child) && strlen($child) <= 0));
        $nullData = ($data == null || !is_array($data) || count($data) <= 0);

        // check if table exists
        if (!$sql = $db->prepare("SHOW TABLES LIKE '$table'"))
            return $this->fail("Could not prepare statement [$db->error]");
        if (!$sql->execute())
            return $this->fail("Could not run query [$sql->error]");
        $result = $sql->get_result();
        $num_rows = $result->num_rows;
        $result->free();
        // if table does not exist
        if ($num_rows <= 0)
            return $this->fail("Table '$table' does not exist in database");

        // data to return at the end
        $response = [];
        // if child not provided, get table data
        if ($nullChild) {
            // if child true, get all table data (including child data)
            if ($child === true) $query = "SELECT * FROM `$table`";
            // if child null or false or other, just get IDs of table data
            else $query = "SELECT id FROM `$table`";
            if (!$sql = $db->prepare($query))
                return $this->fail("Could not prepare statement [$db->error]");
            if (!$sql->execute())
                return $this->fail("Could not run query [$sql->error]");
            $result = $sql->get_result();
            $num_rows = $result->num_rows;
            if ($num_rows > 0) {
                if ($child === true) {
                    while (($row = $result->fetch_assoc()) !== null)
                        array_push($response, $row);
                } else {
                    while (($row = $result->fetch_assoc()) !== null)
                        array_push($response, $row['id']);
                }
            }
            $result->free();
        }
        // if child provided, get data of specific child in table
        elseif (is_string($child)) {
            // if data not provided, get all child data
            if ($nullData) $query = "SELECT * FROM `$table` WHERE id=?";
            // if data provided, get specified data
            else $query = "SELECT " . implode(',', $data) . " FROM `$table` WHERE id=?";
            if (!$sql = $db->prepare($query))
                return $this->fail("Could not prepare statement [$db->error]");
            $sql->bind_param('s', $child);
            if (!$sql->execute())
                return $this->fail("Could not run query [$sql->error]");
            $result = $sql->get_result();
            $num_rows = $result->num_rows;
            if ($num_rows == 1) {
                $result_assoc = $result->fetch_assoc();
                if (count($data) == 1) $response = $result_assoc[$data[0]];
                else $response = $result_assoc;
            } else {
                if (!$nullData && count($data) == 1) {
                    while (($row = $result->fetch_assoc()) !== null)
                        array_push($response, $row[$data[0]]);
                } else {
                    while (($row = $result->fetch_assoc()) !== null)
                        array_push($response, $row);
                }
            }
            $result->free();
        }
        // if child is array, get children with specified data
        elseif (is_array($child)) {
            // loop through desired data
            $where = '';
            $types = '';
            $whereKeyword = 'WHERE';
            $expectedValues = [];
            $lastNextOpLength = 0;
            foreach ($child as $attribute => $value) {
                if ($attribute === 'where') {
                    $whereKeyword = $value;
                    continue;
                }
                if (!is_string($attribute))
                    $attribute = $value['attribute'];
                if (is_array($value)) {
                    // create condition
                    if (isset($value['condition']) && is_string($value['condition'])) {
                        $where .= $attribute . (strlen($value['condition']) >= 1 && substr($value['condition'], 0, 1) == '=' ? '' : ' ') . $value['condition'];
                        $expected = @$value['expected'];
                        if (isset($expected)) {
                            if (!is_array($expected))
                                $expected = [ $expected ];
                            for ($j = 0; $j < count($expected); $j++) {
                                if (is_array($expected[$j])) {
                                    array_push($expectedValues, $expected[$j]['val']);
                                    $types .= $expected[$j]['type'];
                                } else {
                                    array_push($expectedValues, $expected[$j]);
                                    $types .= 's';
                                }
                            }
                        }
                    } else {
                        $where .= $attribute;
                        $expected = @$value['expected'];
                        if (isset($expected)) {
                            $q = '?';
                            if (@$value['prepare'] === false)
                                $q = (is_array($expected)) ? $expected['val'] : $expected;
                            if (is_string($value['whereOperator']))
                                $where .= $value['whereOperator'] . $q;
                            else $where .= "=$q";
                            if ($q == '?') {
                                if (is_array($expected)) {
                                    array_push($expectedValues, $expected['val']);
                                    $types .= $expected['type'];
                                } else {
                                    array_push($expectedValues, $expected[$j]);
                                    $types .= 's';
                                }
                            }
                        }
                    }
                    // create next (joining) operator
                    if (!is_string(@$value['nextOperator']))
                        $value['nextOperator'] = '';
                    $where .= ' ' . $value['nextOperator'] . ' ';
                    $lastNextOpLength = 1;
                } else {
                    $where .= "$attribute=? AND ";
                    $lastNextOpLength = 5;
                    array_push($expectedValues, $value);
                    $types .= 's';
                }
            }
            $where = substr($where, 0, strlen($where) - $lastNextOpLength);
            // if data not provided, get all child data
            if ($nullData) $query = "SELECT * FROM `$table` $whereKeyword $where";
            // if data provided, get specified data
            else $query = "SELECT " . implode(',', $data) . " FROM `$table` $whereKeyword $where";

            $bind_params = array_merge([$types], $expectedValues);

            for ($i = 0; $i < count($bind_params); $i++)
                $bind_params[$i] = &$bind_params[$i];
            if (($sql = $db->prepare($query)) === false)
                return $this->fail("Could not prepare statement [$db->error]");
            call_user_func_array([$sql, 'bind_param'], $bind_params);
            if ($sql->execute() === false)
                return $this->fail("Could not run query [$sql->error]");
            $result = $sql->get_result();
            $num_rows = $result->num_rows;
            if ($num_rows == 1) {
                if (!$nullData && count($data) == 1) $response = $result->fetch_assoc()[$data[0]];
                else $response = $result->fetch_assoc();
            } else {
                if (!$nullData && count($data) == 1) {
                    while (($row = $result->fetch_assoc()) !== null)
                        array_push($response, $row[$data[0]]);
                } else {
                    while (($row = $result->fetch_assoc()) !== null)
                        array_push($response, $row);
                }
            }
            $result->free();
        }

        // final type checking
        if (is_array($response) && count($response) == 0)
            return null;
        return $response;
    }

    // method for getting logged errors
    public function error($num = 0) {
        $numErrors = count($this->errors);
        if ($num === true) return $numErrors;
        elseif ($num < 0 || $num >= $numErrors)
            return false;
        return $this->errors[$numErrors - 1 - $num];
    }

    // PRIVATE (CONVENIENCE) METHODS

    // private convenience method for generating pseudo-random keys/IDs
    private function id($length = 10) {
        $key = '';
        $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        for ($i = 0; $i < $length; $i++)
            $key .= $chars[rand(0, strlen($chars) - 1)];
        return $key;
    }

    // private convenience method for logging errors
    private function fail($message) {
        $e = new Exception();
        array_push($this->errors, "[DOLPHIN] Error - $message - (" . $e->getTraceAsString() . ")");
        return false;
    }

    // private convenience method for logging warnings
    private function warn($message) {
        $e = new Exception();
        array_push($this->errors, "[DOLPHIN] Warning - $message - (" . $e->getTraceAsString() . ")");
        return false;
    }
}

?>
