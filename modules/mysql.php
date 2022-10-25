<?php

class mysql
{

    public $MySQLi;
    private String $db_host = 'localhost';
    private String $db_user = 'root';
    private String $db_password = 'root';
    private String $db_db = 'information_schema';
    private String $db_port = "3306";
    private $debug;

    function __construct($host, $user, $password, $database_name, $port, $debug) {
        $this->db_host = $host;
        $this->db_user = $user;
        $this->db_password = $password;
        $this->db_db = $database_name;
        $this->db_port = $port;
        $this->debug = $debug;
    }
    public function Connect(){
        $this->MySQLi = @new mysqli(
            $this->db_host,
            $this->db_user,
            $this->db_password,
            $this->db_db,
            $this->db_port
        );

        if ($this->MySQLi->connect_error) {
            $this->debug->Err($this->MySQLi->connect_errno);
            $this->debug->Err($this->MySQLi->connect_error);
            exit();
        } else {
            return true;
        }

    }
    public function Disconnect(): bool
    {
        if($this->MySQLi->close()){
            return true;
        } else {
            return false;
            $this->debug->Err("Could not close mysql connection.");
        }
    }
    public function CreateDB($DB_name){
        $sql = "CREATE DATABASE $DB_name";
        return $this->MySQLi->query($sql);
    }
    public function CheckIfDataExist($rows, $table, $where="", $limit="", $order=""): bool {
        $w = "WHERE $where";
        $o = "ORDER BY $order";
        $l = "LIMIT $limit";

        $sql = "SELECT $rows FROM $table $w $o $l";
        $result = mysqli_query($this->MySQLi, $sql);

        if (mysqli_num_rows($result) > 0) {
            return true;
        } else {
            return false;
        }
    }
    public function GetData($rows, $table, $where="", $limit="", $order=""){
        $w = "WHERE $where";
        $o = "ORDER BY $order";
        $l = "LIMIT $limit";

        $sql = "SELECT $rows FROM $table $w $o $l";
        if ($result = mysqli_query($this->MySQLi, $sql)) {
            $myArray = array();
            while ($row = mysqli_fetch_row($result)) {
                $myArray[] = $row;
            }
            mysqli_free_result($result);
            return json_encode($myArray);
        }
    }
    public function InsertData($table, $rows, $values) {
        $sql = "INSERT INTO $table ($rows) VALUES ($values)";
        return $this->MySQLi->query($sql);
    }
    public function UpdateData($table, $field, $value, $where){
        $sql = "UPDATE $table SET $field='$value' WHERE $where";
        return $this->MySQLi->query($sql);
    }
    public function DeleteData($table, $where=""){
        $sql = "DELETE FROM $table WHERE $where";
        return $this->MySQLi->query($sql);
    }

}