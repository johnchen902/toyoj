<?php
namespace Toyoj;
class Login {
    private $segment;
    public function __construct($container) {
        $this->segment = $container->session->getSegment('Toyoj\Login');
    }
    public function isLoggedIn() {
        return $this->segment->get('user_id', 0);
    }
    public function getUserId() {
        if(!$this->isLoggedIn())
            throw new \LogicException("user is not logged in");
        return $this->segment->get('user_id');
    }
    public function login(int $user_id) {
        if($this->isLoggedIn())
            throw new \LogicException("user is logged in");
        if($user_id <= 0)
            throw new \InvalidArgumentException("invalid user_id");
        $this->segment->set('user_id', $user_id);
    }
    public function logout() {
        if(!$this->isLoggedIn())
            throw new \LogicException("user is not logged in");
        $this->segment->set('user_id', 0);
    }
};
