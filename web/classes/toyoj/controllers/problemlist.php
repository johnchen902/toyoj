<?php
namespace Toyoj\Controllers;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
class ProblemList {
    public static function get($c, Request $request, Response $response) {
        $problems = $c->db->query("SELECT p.id, p.title, p.create_time, p.manager_id, u.username AS manager_name, p.ready FROM problems AS p, users AS u WHERE p.manager_id = u.id ORDER BY p.id");
        $canaddnewproblem = $c->permissions->checkNewProblem();
        return $c->view->render($response, "problem-list.html",
            [
                "problems" => $problems,
                "canaddnewproblem" => $canaddnewproblem,
            ]);
    }
};
?>
