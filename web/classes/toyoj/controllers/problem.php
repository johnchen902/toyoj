<?php
namespace Toyoj\Controllers;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use \redirect as redirect;
class Problem {
    public static function get($c, Request $request, Response $response) {
        $pid = $request->getAttribute("pid");
        $q = $c->qf->newSelect()
            ->cols([
                "p.id",
                "p.title",
                "p.statement",
                "p.create_time",
                "p.manager_id",
                "u.username AS manager_name",
                "p.ready"
            ])
            ->from("problems AS p")
            ->innerJoin("users AS u", "p.manager_id = u.id")
            ->orderBy(["p.id"])
            ->where("p.id = ?", $pid)
            ;
        $problem = $c->db->fetchOne($q->getStatement(), $q->getBindValues());
        if(!$problem)
            return ($c->errorview)($response, 404, "No Such Problem");
        $problem["cansubmit"] = self::checkSubmit($c, $pid);
        $problem["canedit"] = false;

        $q = $c->qf->newSelect()
            ->cols(["id", "score"])
            ->from("subtasks")
            ->where("problem_id = ?", $pid)
            ->orderBy(["id"])
            ;
        $subtasks = $c->db->fetchAssoc($q->getStatement(), $q->getBindValues());

        $q = $c->qf->newSelect()
            ->cols(["id", "time_limit", "memory_limit", "checker_name"])
            ->from("testcases")
            ->where("problem_id = ?", $pid)
            ->orderBy(["id"])
            ;
        $testcases = $c->db->fetchAssoc($q->getStatement(), $q->getBindValues());

        $q = $c->qf->newSelect()
            ->cols(["subtask_id", "testcase_id"])
            ->from("subtask_testcases")
            ->where("problem_id = ?", $pid)
            ->orderBy(["subtask_id", "testcase_id"])
            ;
        $rels = $c->db->fetchAll($q->getStatement(), $q->getBindValues());

        foreach($subtasks as &$subtask)
            $subtask["testcase_ids"] = [];
        foreach($testcases as &$testcase)
            $testcase["subtask_ids"] = [];
        foreach($rels as $rel) {
            $subtask_id = $rel["subtask_id"];
            $testcase_id = $rel["testcase_id"];
            $subtasks[$subtask_id]["testcase_ids"][] = $testcase_id;
            $testcases[$testcase_id]["subtask_ids"][] = $subtask_id;
        }
        foreach($subtasks as &$subtask)
            $subtask["testcase_ids"] = implode($subtask["testcase_ids"], ", ");
        foreach($testcases as &$testcase)
            $testcase["subtask_ids"] = implode($testcase["subtask_ids"], ", ");

        return $c->view->render($response, "problem.html", [
            "problem" => $problem,
            "subtasks" => $subtasks,
            "testcases" => $testcases,
        ]);
    }
    public static function post($c, Request $request, Response $response) {
        $pid = $request->getAttribute("pid");
        $login = $c->session["login"] ?? false;
        $language = $request->getParsedBodyParam("language");
        $code = $request->getParsedBodyParam("code");
        $code = str_replace("\r\n", "\n", $code);

        $errors = $c->forms->validateSubmission($language, $code);
        if($errors) {
            foreach($errors as $e)
                $c->messages[] = $e;
            return redirect($response, 303,
                $c->router->pathFor("problem", ["pid" => $pid]));
        }

        $c->db->exec("BEGIN TRANSACTION ISOLATION LEVEL SERIALIZABLE");
        if(!self::checkSubmit($c, $pid)) {
            $c->db->exec("ROLLBACK");
            $c->messages[] = "You are not allowed to submit on this problem.";
            return redirect($response, 303, 
                $c->router->pathFor("problem", ["pid" => $pid]));
        }

        $q = $c->qf->newInsert()
            ->into("submissions")
            ->cols([
                "problem_id" => $pid,
                "submitter_id" => $login,
                "language_name" => $language,
                "code" => $code,
            ])
            ->returning(["id"])
            ;
        $id = $c->db->fetchValue($q->getStatement(), $q->getBindValues());
        $c->db->exec("COMMIT");

        return redirect($response, 303,
            $c->router->pathFor("submission", ["sid" => $id]));
    }
    public static function checkSubmit($c, $problem_id) {
        $login = $c->session["login"];
        if(!$login)
            return false;
        $q = $c->qf->newSelect()
            ->cols(["1"])
            ->from("problems")
            ->where("id = ?", $problem_id)
            ->where("ready OR (manager_id = ?)", $login)
            ;
        $one = $c->db->fetchValue($q->getStatement(), $q->getBindValues());
        return boolval($one);
    }
};
?>
