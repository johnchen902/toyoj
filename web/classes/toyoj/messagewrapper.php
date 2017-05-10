<?php
namespace Toyoj;
class MessageWrapper implements \Iterator, \ArrayAccess {
    public function __construct($session) {
        $this->session = $session;
        if(!isset($session["messages"]))
            $session["messages"] = array();
    }

    public function current() {
        return $this->session["messages"][0];
    }
    public function key() {
        return 0;
    }
    public function next() {
        array_shift($this->session["messages"]);
    }
    public function rewind() {
        // No-op
    }
    public function valid() {
        return isset($this->session["messages"][0]);
    }


    public function offsetExists($offset) {
        return isset($this->session["messages"][$offset]);
    }
    public function &offsetGet($offset) {
        return $this->session["messages"][$offset];
    }
    public function offsetSet($offset, $value) {
        if(is_null($offset))
            $this->session["messages"][] = $value;
        else
            $this->session["messages"][$offset] = $value;
    }
    public function offsetUnset($offset) {
        unset($this->session["messages"][$offset]);
    }
};
?>
