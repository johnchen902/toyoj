<?php
namespace Toyoj\Controllers;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
class Index {
    public static function get($c, Request $request, Response $response) {
        return $c->view->render($response, "index.html");
    }
};
?>
