<?php
namespace Toyoj;
class SessionWrapper implements \ArrayAccess {
    public function __construct() {
        session_start();
    }
    public function offsetExists($offset) {
        return isset($_SESSION[$offset]);
    }
    public function &offsetGet($offset) {
        return $_SESSION[$offset];
    }
    public function offsetSet($offset, $value) {
        if(is_null($offset))
            $_SESSION[] = $value;
        else
            $_SESSION[$offset] = $value;
    }
    public function offsetUnset($offset) {
        unset($_SESSION[$offset]);
    }
};
?>
