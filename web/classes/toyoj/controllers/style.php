<?php
namespace Toyoj\Controllers;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
class Style {
    public static function showAll($c, Request $request, Response $response) {
        return $c->view->render($response, "style-list.html");
    }
    public static function setStyle($c, Request $request, Response $response) {
        $title = $request->getParsedBodyParam("title");
        $c->stylesheets->setPreferredTitle($title);
        return Utilities::redirectRoute($response, 303, $c, "style-list");
    }
};
?>
