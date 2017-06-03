<?php
namespace Toyoj\Controllers;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use \redirect as redirect;
class SubtaskEdit {
    public static function get($c, Request $request, Response $response) {
        $problem_id = $request->getAttribute("pid");
        $q = $c->qf->newSelect()
            ->cols(["id", "title"])
            ->from("problems")
            ->where("id = ?", $problem_id)
            ;
        $problem = $c->db->fetchOne($q->getStatement(), $q->getBindValues());
        if(!$problem)
            return ($c->errorview)($response, 404, "No Such Problem");

        $subtask_id = $request->getAttribute("subtaskid");
        $q = $c->qf->newSelect()
            ->cols(["id", "score"])
            ->from("subtasks")
            ->where("id = ?", $subtask_id)
            ->where("problem_id = ?", $problem_id)
            ;
        $subtask = $c->db->fetchOne($q->getStatement(), $q->getBindValues());
        if(!$subtask)
            return ($c->errorview)($response, 404, "No Such Subtask");

        if(!self::checkEditSubtask($c, $problem_id, $subtask_id))
            return ($c->errorview)($response, 403, "Forbidden");

        $q = $c->qf->newSelect()
            ->cols(["id"])
            ->from("testcases")
            ->where("problem_id = ?", $problem_id)
            ->orderBy(["id"])
            ;
        $testcases = $c->db->fetchAssoc($q->getStatement(), $q->getBindValues());

        $q = $c->qf->newSelect()
            ->cols(["testcase_id"])
            ->from("subtask_testcases")
            ->where("subtask_id = ?", $subtask_id)
            ;
        $includeds = $c->db->fetchCol($q->getStatement(), $q->getBindValues());

        foreach($testcases as &$testcase)
            $testcase["included"] = false;
        foreach($includeds as $included)
            $testcases[$included]["included"] = true;

        return $c->view->render($response, "subtask-edit.html", [
            "problem" => $problem,
            "subtask" => $subtask,
            "testcases" => $testcases,
        ]);
    }
    public static function post($c, Request $request, Response $response) {
        $delete = $request->getParsedBodyParam("delete");
        if($delete) {
            return self::doDelete($c, $request, $response);
        }
        return self::doUpdate($c, $request, $response);
    }

    private static function doUpdate($c, Request $request, Response $response) {
        $problem_id = $request->getAttribute("pid");
        $subtask_id = $request->getAttribute("subtaskid");
        $score = (int) $request->getParsedBodyParam("score");
        $testcaseids = $request->getParsedBodyParam("testcaseids");
        $alltestcaseids = $request->getParsedBodyParam("alltestcaseids");
        $errors = $c->forms->validateSubtask($score, $testcaseids, $alltestcaseids);
        if($errors) {
            foreach($errors as $e)
                $c->messages[] = $e;
            return redirect($response, 303,
                $c->router->pathFor("subtask-edit",
                ["pid" => $problem_id, "subtaskid" => $subtask_id]));
        }

        $c->db->exec("BEGIN TRANSACTION ISOLATION LEVEL SERIALIZABLE");

        if(!self::checkEditSubtask($c, $problem_id, $subtask_id)) {
            $c->db->exec("ROLLBACK");
            $c->messages[] = "You are not allowed to delete this subtask.";
            return redirect($response, 303,
                $c->router->pathFor("subtask-edit",
                ["pid" => $problem_id, "subtaskid" => $subtask_id]));
        }

        $q = $c->qf->newUpdate()
            ->table("subtasks")
            ->cols(["score" => $score])
            ->where("id = ?", $subtask_id)
            ->where("problem_id = ?", $problem_id)
            ;
        $cnt = $c->db->fetchAffected($q->getStatement(), $q->getBindValues());
        if(!$cnt) {
            $c->db->exec("ROLLBACK");
            $c->messages[] = "Failed to update subtask.";
            return redirect($response, 303,
                $c->router->pathFor("subtask-edit",
                ["pid" => $problem_id, "subtaskid" => $subtask_id]));
        }

        $q = $c->qf->newInsert()->into("subtask_testcases");

        $removing = [];
        foreach($alltestcaseids as $testcase_id) {
            if(in_array($testcase_id, $testcaseids)) {
                $q->cols([
                    "problem_id" => $problem_id,
                    "subtask_id" => $subtask_id,
                    "testcase_id" => $testcase_id,
                ]);
                $q->addRow();
            } else {
                $removing[] = $testcase_id;
            }
        }
        $c->db->fetchAffected($q->getStatement() . " ON CONFLICT DO NOTHING",
            $q->getBindValues());

        $q = $c->qf->newDelete()->from("subtask_testcases")
            ->where("subtask_id = ?", $subtask_id)
            ->where("testcase_id IN (?)", $removing)
            ;
        $c->db->fetchAffected($q->getStatement(), $q->getBindValues());

        $c->db->exec("COMMIT");
        $c->messages[] = "Subtask edited.";
        return redirect($response, 303,
            $c->router->pathFor("subtask",
            ["pid" => $problem_id, "subtaskid" => $subtask_id]));
    }

    private static function doDelete($c, Request $request, Response $response) {
        $problem_id = $request->getAttribute("pid");
        $subtask_id = $request->getAttribute("subtaskid");

        $c->db->exec("BEGIN TRANSACTION ISOLATION LEVEL SERIALIZABLE");
        if(!self::checkEditSubtask($c, $problem_id, $subtask_id)) {
            $c->db->exec("ROLLBACK");
            $c->messages[] = "You are not allowed to delete this subtask.";
            return redirect($response, 303,
                $c->router->pathFor("subtask-edit",
                ["pid" => $problem_id, "subtaskid" => $subtask_id]));
        }

        $q = $c->qf->newDelete()
            ->from("subtasks")
            ->where("problem_id = ?", $problem_id)
            ->where("id = ?", $subtask_id)
            ;
        $cnt = $c->db->fetchAffected($q->getStatement(), $q->getBindValues());
        if(!$cnt) {
            $c->db->exec("ROLLBACK");
            $c->messages[] = "Failed to delete subtask.";
            return redirect($response, 303,
                $c->router->pathFor("subtask-edit",
                ["pid" => $problem_id, "subtaskid" => $subtask_id]));
        }
        $c->db->exec("COMMIT");

        $c->messages[] = "Subtask deleted.";
        return redirect($response, 303,
            $c->router->pathFor("subtask-list", ["pid" => $problem_id]));
    }
    public static function checkEditSubtask($c, $problem_id, $subtask_id) {
        $login = $c->session["login"];
        if(!$login)
            return false;
        $q = $c->qf->newSelect()
            ->cols(["1"])
            ->from("subtasks AS s")
            ->innerJoin("problems AS p", "p.id = s.problem_id")
            ->where("s.id = ?", $subtask_id)
            ->where("p.id = ?", $problem_id)
            ->where("p.manager_id = ?", $login)
            ;
        $one = $c->db->fetchValue($q->getStatement(), $q->getBindValues());
        return boolval($one);
    }
};
?>
