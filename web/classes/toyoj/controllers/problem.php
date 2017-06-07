<?php
namespace Toyoj\Controllers;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
class Problem {
    public static function showAll($c, Request $request, Response $response) {
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
        $canaddnewproblem = Problem::checkCreate($c);
        return $c->view->render($response, "problem-list.html", [
            "problems" => $problems,
            "canaddnewproblem" => $canaddnewproblem,
        ]);
    }

    public static function show($c, Request $request, Response $response) {
        $pid = $request->getAttribute("pid");
        $problem = self::getProblemWithSubtasksAndTestcases($c, $pid);
        if(!$problem)
            return ($c->errorview)($response, 404, "No Such Problem");
        $problem["cansubmit"] = self::checkSubmit($c, $pid);
        $problem["canedit"] = self::checkEdit($c, $pid);
        return $c->view->render($response, "problem.html",
                ["problem" => $problem]);
    }
    public static function submit($c, Request $request, Response $response) {
        return (new class extends AbstractPostHandler {
            protected function getAttributeNames() {
                return ["pid"];
            }
            protected function getFieldNames() {
                return ["language", "code"];
            }
            protected function transformData(array &$data) {
                $data["code"] = Utilities::crlf2lf($data["code"]);
            }
            protected function verifyData(array $data) {
                return Problem::validateSubmission($data);
            }
            protected function checkPermissions($c, array $data) {
                return Problem::checkSubmit($c, $data["pid"]);
            }
            protected function getSuccessMessage() {
                return "Submitted";
            }
            protected function getSuccessLocation($c, array $data, $result) {
                return $c->router->pathFor("submission", ["sid" => $result]);
            }
            protected function getErrorLocation(
                    $c, array $data, \Exception $e) {
                return $c->router->pathFor("problem", ["pid" => $data["pid"]]);
            }
            protected function transaction($c, array $data) {
                $q = $c->qf->newInsert()
                    ->into("submissions")
                    ->cols([
                        "problem_id" => $data["pid"],
                        "submitter_id" => $c->session["login"],
                        "language_name" => $data["language"],
                        "code" => $data["code"],
                    ])
                    ->returning(["id"]);
                return $c->db->fetchValue(
                        $q->getStatement(), $q->getBindValues());
            }
        })->handle($c, $request, $response);
    }
    public static function checkSubmit($c, $problem_id) {
        $login = $c->session["login"];
        if(!$login)
            return false;
        $q = $c->qf->newSelect()
            ->cols(["1"])
            ->from("problems")
            ->where("id = ?", $problem_id)
            ->where("ready OR (manager_id = ?)", $login);
        $ok = $c->db->fetchValue($q->getStatement(), $q->getBindValues());
        return boolval($ok);
    }
    public static function validateSubmission(array $data) {
        $e = [];
        Utilities::validateString($e, $data["language"], "Language", 1, 32);
        Utilities::validateString($e, $data["code"], "Code", 1, 65536);
        return $e;
    }

    public static function showCreatePage(
            $c, Request $request, Response $response) {
        if(!self::checkCreate($c))
            return ($c->errorview)($response, 403, "Forbidden");
        return $c->view->render($response, "problem-new.html");
    }
    public static function create($c, Request $request, Response $response) {
        return (new class extends AbstractPostHandler {
            protected function getFieldNames() {
                return ["title", "statement"];
            }
            protected function transformData(array &$data) {
                $data["statement"] = Utilities::crlf2lf($data["statement"]);
            }
            protected function verifyData(array $data) {
                return Problem::validateProblem($data);
            }
            protected function checkPermissions($c, array $data) {
                return Problem::checkCreate($c);
            }
            protected function getSuccessMessage() {
                return "Problem Created";
            }
            protected function getSuccessLocation($c, array $data, $result) {
                return $c->router->pathFor("problem", ["pid" => $result]);
            }
            protected function getErrorLocation(
                    $c, array $data, \Exception $e) {
                return $c->router->pathFor("problem-new");
            }
            protected function transaction($c, array $data) {
                $q = $c->qf->newInsert()
                    ->into("problems")
                    ->cols([
                        "title" => $data["title"],
                        "statement" => $data["statement"],
                        "manager_id" => $c->session["login"],
                        "ready" => false,
                    ])
                    ->returning(["id"]);
                return $c->db->fetchValue(
                        $q->getStatement(), $q->getBindValues());
            }
        })->handle($c, $request, $response);
    }
    public static function checkCreate($c) {
        $login = $c->session["login"];
        if(!$login)
            return false;
        $q = $c->qf->newSelect()
            ->cols(["1"])
            ->from("user_permissions")
            ->where("user_id = ?", $login)
            ->where("permission_name = 'newproblem'")
            ;
        $one = $c->db->fetchValue($q->getStatement(), $q->getBindValues());
        return boolval($one);
    }

    public static function showEditPage(
            $c, Request $request, Response $response) {
        $pid = $request->getAttribute("pid");
        $problem = self::getProblemWithSubtasksAndTestcases($c, $pid);
        if(!$problem)
            return ($c->errorview)($response, 404, "No Such Problem");
        $page = self::checkEdit($c, $pid) ? "problem-edit.html" :
                "problem-source.html";
        return $c->view->render($response, $page, ["problem" => $problem]);
    }
    public static function edit($c, Request $request, Response $response) {
        return (new class extends AbstractPostHandler {
            protected function getAttributeNames() {
                return ["pid"];
            }
            protected function getFieldNames() {
                return ["title", "statement", "ready"];
            }
            protected function transformData(array &$data) {
                $data["statement"] = Utilities::crlf2lf($data["statement"]);
                $data["ready"] = $data["ready"] === "ready";
            }
            protected function verifyData(array $data) {
                return Problem::validateProblem($data);
            }
            protected function checkPermissions($c, array $data) {
                return Problem::checkEdit($c, $data["pid"]);
            }
            protected function getSuccessMessage() {
                return "Problem Edited";
            }
            protected function getSuccessLocation($c, array $data, $result) {
                return $c->router->pathFor("problem", ["pid" => $result]);
            }
            protected function getErrorLocation(
                    $c, array $data, \Exception $e) {
                return $c->router->pathFor("problem-edit", ["pid" => $result]);
            }
            protected function transaction($c, array $data) {
                $q = $c->qf->newUpdate()
                    ->table("problems")
                    ->cols([
                        "title" => $data["title"],
                        "statement" => $data["statement"],
                        "ready" => $data["ready"],
                    ])
                    ->where("id = ?", $pid);
                if($c->db->fetchAffected(
                        $q->getStatement(), $q->getBindValues()) != 1)
                    throw new \Exception();
            }
        })->handle($c, $request, $response);
    }
    public static function checkEdit($c, $problem_id) {
        $login = $c->session["login"];
        if(!$login)
            return false;
        $q = $c->qf->newSelect()
            ->cols(["1"])
            ->from("problems")
            ->where("id = ?", $problem_id)
            ->where("manager_id = ?", $login)
            ;
        $ok = $c->db->fetchValue($q->getStatement(), $q->getBindValues());
        return boolval($ok);
    }
    public static function validateProblem(array &$data) {
        $e = [];
        Utilities::validateString($e, $data["title"], "Title", 1, 128);
        Utilities::validateString($e, $data["statement"], "Statement", 1, 65536);
        return $e;
    }

    private static function getProblem($c, $problem_id) {
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
            ->where("p.id = ?", $problem_id)
            ;
        return $c->db->fetchOne($q->getStatement(), $q->getBindValues());
    }
    private static function getSubtasksByProblemId($c, $problem_id) {
        $q = $c->qf->newSelect()
            ->cols(["id", "score"])
            ->from("subtasks")
            ->where("problem_id = ?", $problem_id)
            ->orderBy(["id"])
            ;
        return $c->db->fetchAssoc($q->getStatement(), $q->getBindValues());
    }
    private static function getTestcasesByProblemId($c, $problem_id) {
        $q = $c->qf->newSelect()
            ->cols(["id", "time_limit", "memory_limit", "checker_name"])
            ->from("testcases")
            ->where("problem_id = ?", $problem_id)
            ->orderBy(["id"])
            ;
        return $c->db->fetchAssoc($q->getStatement(), $q->getBindValues());
    }
    private static function fillSubtasksAndTestcasesRelation(
            $c, $problem_id, &$subtasks, &$testcases) {
        $q = $c->qf->newSelect()
            ->cols(["subtask_id", "testcase_id"])
            ->from("subtask_testcases")
            ->where("problem_id = ?", $problem_id)
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
    }
    private static function getProblemWithSubtasksAndTestcases(
            $c, $problem_id) {
        $problem = self::getProblem($c, $problem_id);
        if($problem) {
            $subtasks = self::getSubtasksByProblemId($c, $problem_id);
            $testcases = self::getTestcasesByProblemId($c, $problem_id);
            self::fillSubtasksAndTestcasesRelation($c, $problem_id,
                    $subtasks, $testcases);
            $problem["subtasks"] = $subtasks;
            $problem["testcases"] = $testcases;
        }
        return $problem;
    }
};
?>
