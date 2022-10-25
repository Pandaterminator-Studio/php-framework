<?php

class debugger

{

    public function Err($message): void
    {
        $time = date("d:m:Y H:i:s",time());
        echo "<br /><br /><font color='red'>[$time] ERROR: $message</font>";
    }

    public function Warn($message): void
    {
        $time = date("d:m:Y H:i:s",time());
        echo "<br /><br /><font color='orange'>[$time] WARNING: $message</font>";
    }

    public function Info($message): void
    {
        $time = date("d:m:Y H:i:s",time());
        echo "<br /><br /><font color='blue'>[$time] INFO: $message</font>";
    }

    public function Status($message): void
    {
        $time = date("d:m:Y H:i:s",time());
        echo "<br /><br /><font color='green'>[$time] STATUS: $message</font>";
    }
}