<?php
namespace Toyoj\Controllers;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use \redirect as redirect;
class SubtaskNew {
    public static function get($c, Request $request, Response $response) {
        $pid = $request->getAttribute("pid");
        $q = $c->qf->newSelect()
            ->cols(["id", "title"])
            ->from("problems")
            ->where("id = ?", $pid)
            ;
        $problem = $c->db->fetchOne($q->getStatement(), $q->getBindValues());
        if(!$problem)
            return ($c->errorview)($response, 404, "No Such Problem");
        if(!self::checkNewSubtask($c, $pid))
            return ($c->errorview)($response, 403, "Forbidden");

        $q = $c->qf->newSelect()
            ->cols(["id"])
            ->from("testcases")
            ->where("problem_id = ?", $pid)
            ->orderBy(["id"])
            ;
        $testcases = $c->db->fetchAll($q->getStatement(), $q->getBindValues());

        return $c->view->render($response, "subtask-new.html", [
            "problem" => $problem,
            "testcases" => $testcases,
        ]);
    }
    public static function post($c, Request $request, Response $response) {
        $pid = $request->getAttribute("pid");
        $score = (int) $request->getParsedBodyParam("score");
        $testcase_ids = $request->getParsedBodyParam("testcaseids");

        $errors = $c->forms->validateSubtask($score, $testcase_ids, $testcase_ids);
        if($errors) {
            foreach($errors as $e)
                $c->messages[] = $e;
            return redirect($response, 303, $c->router->pathFor("subtask-new", ["pid" => $pid]));
        }

        $c->db->exec("BEGIN TRANSACTION ISOLATION LEVEL SERIALIZABLE");
        if(!self::checkNewSubtask($c, $pid)) {
            $c->db->exec("ROLLBACK");
            $c->messages[] = "You are not allowed to add new subtask for this problem.";
            return redirect($response, 303, $c->router->pathFor("subtask-new", ["pid" => $pid]));
        }

        $q = $c->qf->newInsert()
            ->into("subtasks")
            ->cols([
                "problem_id" => $pid,
                "score" => $score,
            ])
            ->returning(["id"])
            ;
        $id = $c->db->fetchValue($q->getStatement(), $q->getBindValues());

        $q = $c->qf->newInsert()
            ->into("subtask_testcases");
        foreach($testcase_ids as $testcase_id) {
            $q->cols([
                "problem_id" => $pid,
                "subtask_id" => $id,
                "testcase_id" => $testcase_id,
            ]);
            $q->addRow();
        }
        $c->db->fetchAffected($q->getStatement(), $q->getBindValues());

        $c->db->exec("COMMIT");

        $c->messages[] = "Subtask added.";
        return redirect($response, 303, $c->router->pathFor("subtask", array("pid" => $pid, "subtaskid" => $id)));
    }
    public static function checkNewSubtask($c, $problem_id) {
        $login = $c->session["login"];
        if(!$login)
            return false;
        $q = $c->qf->newSelect()
            ->cols(["1"])
            ->from("problems")
            ->where("id = ?", $problem_id)
            ->where("manager_id = ?", $login)
            ;
        $one = $c->db->fetchValue($q->getStatement(), $q->getBindValues());
        return boolval($one);
    }
};
?>
