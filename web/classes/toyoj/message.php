<?php
namespace Toyoj;
class Message {
    private $message;
    private $classes;
    public function __construct(string $message, array $classes = []) {
        $this->message = $message;
        $this->classes = $classes;
    }
    public function getMessage() {
        return $this->message;
    }
    public function getClasses() {
        return $this->classes;
    }
    public static function wrap($message) {
        if($message instanceof Message)
            return $message;
        if(is_string($message))
            return new Message($message);
        try {
            // check interface
            $message->getMessage();
            $message->getClasses();
            return $message;
        } catch (\Error $e) {
            throw new \InvalidArgumentException("message cannot be wrapped");
        }
    }
}
