<?php
namespace Toyoj\Controllers;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
class ProblemList {
    public static function get($c, Request $request, Response $response) {
        $q = $c->qf->newSelect()
            ->cols([
                "p.id",
                "p.title",
                "p.create_time",
                "p.manager_id",
                "u.username AS manager_name",
                "p.ready"
            ])
            ->from("problems AS p")
            ->innerJoin("users AS u", "p.manager_id = u.id")
            ->orderBy(["p.id"])
            ;
        $problems = $c->db->fetchAll($q->getStatement(), $q->getBindValues());
        $canaddnewproblem = ProblemNew::checkNewProblem($c);
        return $c->view->render($response, "problem-list.html", [
            "problems" => $problems,
            "canaddnewproblem" => $canaddnewproblem,
        ]);
    }
};
?>
