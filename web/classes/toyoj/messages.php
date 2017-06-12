<?php
namespace Toyoj;
class Messages {
    const MESSAGES_KEY = 'messages';
    private $segment;
    public function __construct($container) {
        $this->segment = $container->session->getSegment('Toyoj\Messages');
    }
    public function listAll() {
        return $this->segment->get(Messages::MESSAGES_KEY, []);
    }
    public function listAllOnce() {
        $messages = $this->listAll();
        $this->segment->set(Messages::MESSAGES_KEY, []);
        return $messages;
    }
    public function addMessage($message) {
        $messages = $this->listAll();
        $messages[] = Message::wrap($message);
        $this->segment->set(Messages::MESSAGES_KEY, $messages);
    }
}
