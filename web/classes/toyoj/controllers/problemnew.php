<?php
namespace Toyoj\Controllers;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use \redirect as redirect;
class ProblemNew {
    public static function get($c, Request $request, Response $response) {
        if(!$c->permissions->checkNewProblem()) {
            return ($c->errorview)($response, 403, "Forbidden");
        }
        return $c->view->render($response, "problem-new.html");
    }
    public static function post($c, Request $request, Response $response) {
        $login = $c->session["login"];
        $title = $request->getParsedBodyParam("title");
        $statement = $request->getParsedBodyParam("statement");

        $statement = str_replace("\r\n", "\n", $statement);

        $errors = $c->forms->validateProblem($title, $statement);
        if($errors) {
            foreach($errors as $e)
                $c->messages[] = $e;
            return redirect($response, 303,
                $c->router->pathFor("problem-new"));
        }

        $c->db->exec("BEGIN TRANSACTION ISOLATION LEVEL SERIALIZABLE");
        if(!$c->permissions->checkNewProblem()) {
            $c->db->exec("ROLLBACK");
            $c->messages[] = "You are not allowed to create new problem.";
            return redirect($response, 303, $c->router->pathFor("problem-new"));
        }
        $q = $c->qf->newInsert()
            ->into("problems")
            ->cols([
                "title" => $title,
                "statement" => $statement,
                "manager_id" => $login,
                "ready" => false,
            ])
            ->returning(["id"])
            ;
        $id = $c->db->fetchValue($q->getStatement(), $q->getBindValues());
        $c->db->exec("COMMIT");

        $c->messages[] = "New problem added.";
        return redirect($response, 303,
            $c->router->pathFor("problem", ["pid" => $id]));
    }
};
?>
