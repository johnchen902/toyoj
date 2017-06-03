<?php
namespace Toyoj\Controllers;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use \redirect as redirect;
class Logout {
    public static function get($c, Request $request, Response $response) {
        return $c->view->render($response, "logout.html");
    }
    public static function post($c, Request $request, Response $response) {
        if(isset($c->session["login"])) {
            unset($c->session["login"]);
            $c->messages[] = "Logged out successfully";
        } else {
            $c->messages[] = "You are not logged in";
        }
        return redirect($response, 303, $c->router->pathFor("login"));
    }
};
?>
