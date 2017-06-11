<?php
namespace Toyoj\Controllers;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
class Subtask {
    public static function showAll($c, Request $request, Response $response) {
        // TODO implement
        $pid = $request->getAttribute("pid");
        return Utilities::redirect($response, 302,
                $c->router->pathFor("problem", ["pid" => $pid]) . "#subtasks");
    }

    public static function show($c, Request $request, Response $response) {
        // TODO implement
        $pid = $request->getAttribute("pid");
        return Utilities::redirect($response, 302,
                $c->router->pathFor("problem", ["pid" => $pid]) . "#subtasks");
    }

    public static function showCreatePage($c, Request $request, Response $response) {
        $pid = $request->getAttribute("pid");
        $problem = Problem::getBaseProblem($c, $pid);
        if(!$problem)
            return ($c->errorview)($response, 404, "No Such Problem");
        if(!self::checkCreate($c, $pid))
            return ($c->errorview)($response, 403, "Forbidden");

        $testcases = Problem::getTestcasesByProblemId($c, $pid);
        $problem["testcases"] = $testcases;

        return $c->view->render($response, "subtask-new.html",
                ["problem" => $problem]);
    }
    public static function create($c, Request $request, Response $response) {
        return (new class extends AbstractPostHandler {
            protected function getAttributeNames() {
                return ["pid"];
            }
            protected function getFieldNames() {
                return ["score", "testcaseids"];
            }
            protected function transformData(array &$data) {
                $data["score"] = (int) $data["score"];
                $data["testcaseids"] = (array) $data["testcaseids"];
            }
            protected function verifyData(array $data) {
                return Subtask::validateSubtask($data);
            }
            protected function checkPermissions($c, array $data) {
                return Subtask::checkCreate($c, $data["pid"]);
            }
            protected function getSuccessMessage() {
                return "Subtask Created";
            }
            protected function getSuccessLocation($c, array $data, $result) {
                return $c->router->pathFor("subtask",
                        ["pid" => $data["pid"], "subtaskid" => $result]);
            }
            protected function getErrorLocation($c, array $data, \Exception $e) {
                return $c->router->pathFor("subtask-new",
                        ["pid" => $data["pid"]]);
            }
            protected function transaction($c, array $data) {
                $q = $c->qf->newInsert()
                    ->into("subtasks")
                    ->cols([
                        "problem_id" => $data["pid"],
                        "score" => $data["score"],
                    ])
                    ->returning(["id"]);
                $id = $c->db->fetchValue($q->getStatement(), $q->getBindValues());

                $q = $c->qf->newUpdate()
                    ->table("subtask_testcases_view")
                    ->cols(["exists" => TRUE])
                    ->where("subtask_id = ?", $id)
                    ->where("testcase_id = ANY (ARRAY[?] :: integer[])",
                        $data["testcaseids"]);
                $cnt = $c->db->fetchAffected($q->getStatement(), $q->getBindValues());
                if($cnt != count($data["testcaseids"]))
                    throw new \Exception();

                return $id;
            }
        })->handle($c, $request, $response);
    }
    public static function checkCreate($c, $problem_id) {
        return Problem::checkEdit($c, $problem_id);
    }
    public static function validateSubtask($data) {
        $e = [];
        if($data["score"] < 1)
            $e[] = "Score is less than 1";
        return $e;
    }

    public static function showEditPage($c, Request $request, Response $response) {
        $problem_id = $request->getAttribute("pid");
        $problem = Problem::getBaseProblem($c, $problem_id);
        if(!$problem)
            return ($c->errorview)($response, 404, "No Such Problem");

        $subtask_id = $request->getAttribute("subtaskid");
        $subtask = self::getSubtaskById($c, $problem_id, $subtask_id);
        if(!$subtask)
            return ($c->errorview)($response, 404, "No Such Subtask");

        if(!self::checkEdit($c, $problem_id, $subtask_id))
            return ($c->errorview)($response, 403, "Forbidden");

        $testcases = self::getTestcaseExistsBySubtaskId($c, $subtask_id);

        $subtask["problem"] = $problem;
        $subtask["testcase_exists"] = $testcases;

        return $c->view->render($response, "subtask-edit.html",
                ["subtask" => $subtask]);
    }
    public static function editOrDelete($c, Request $request, Response $response) {
        if($request->getParsedBodyParam("delete") === "delete") {
            return self::delete($c, $request, $response);
        } else if($request->getParsedBodyParam("update") === "update") {
            return self::edit($c, $request, $response);
        } else {
            return Utilities::redirectRoute($response, 303, "subtask-edit", [
                "pid" => $request->getAttribute("pid"),
                "subtaskid" => $request->getAttribute("subtaskid"),
            ]);
        }
    }
    private static function edit($c, Request $request, Response $response) {
        return (new class extends AbstractPostHandler {
            protected function getAttributeNames() {
                return ["pid", "subtaskid"];
            }
            protected function getFieldNames() {
                return ["score", "testcaseids"];
            }
            protected function transformData(array &$data) {
                $data["score"] = (int) $data["score"];
                $data["testcaseids"] = (array) $data["testcaseids"];
            }
            protected function verifyData(array $data) {
                return Subtask::validateSubtask($data);
            }
            protected function checkPermissions($c, array $data) {
                return Subtask::checkEdit($c, $data["pid"],
                        $data["subtask_id"]);
            }
            protected function getSuccessMessage() {
                return "Subtask Edited";
            }
            protected function getSuccessLocation($c, array $data, $result) {
                return $c->router->pathFor("subtask", [
                    "pid" => $data["pid"],
                    "subtaskid" => $data["subtaskid"],
                ]);
            }
            protected function getErrorLocation($c, array $data, \Exception $e) {
                return $c->router->pathFor("subtask-edit", [
                    "pid" => $data["pid"],
                    "subtaskid" => $data["subtaskid"],
                ]);
            }
            protected function transaction($c, array $data) {
                $q = $c->qf->newUpdate()
                    ->table("subtasks")
                    ->cols(["score" => $data["score"]])
                    ->where("id = ?", $data["subtaskid"])
                    ->where("problem_id = ?", $data["pid"]);
                $cnt = $c->db->fetchAffected($q->getStatement(), $q->getBindValues());
                if($cnt != 1)
                    throw new \Exception();

                $q = $c->qf->newUpdate()
                    ->table("subtask_testcases_view")
                    ->set("exists", "testcase_id = ANY (ARRAY[:tests] :: integer[])")
                    ->where("subtask_id = ?", $data["subtaskid"])
                    ->bindValue("tests", $data["testcaseids"]);
                $c->db->perform($q->getStatement(), $q->getBindValues());
            }
        })->handle($c, $request, $response);
    }
    private static function delete($c, Request $request, Response $response) {
        return (new class extends AbstractPostHandler {
            protected function getAttributeNames() {
                return ["pid", "subtaskid"];
            }
            protected function checkPermissions($c, array $data) {
                return Subtask::checkDelete($c, $data["pid"],
                        $data["subtask_id"]);
            }
            protected function getSuccessMessage() {
                return "Subtask Deleted";
            }
            protected function getSuccessLocation($c, array $data, $result) {
                return $c->router->pathFor("subtask-list",
                        ["pid" => $data["pid"]]);
            }
            protected function getErrorLocation($c, array $data, \Exception $e) {
                return $c->router->pathFor("subtask-edit", [
                    "pid" => $data["pid"],
                    "subtaskid" => $data["subtaskid"],
                ]);
            }
            protected function transaction($c, array $data) {
                $q = $c->qf->newDelete()
                    ->from("subtasks")
                    ->where("problem_id = ?", $data["pid"])
                    ->where("id = ?", $data["subtaskid"]);
                $cnt = $c->db->fetchAffected($q->getStatement(), $q->getBindValues());
                if($cnt != 1)
                    throw new \Exception();
            }
        })->handle($c, $request, $response);
    }
    public static function checkEdit($c, $problem_id, $subtask_id) {
        return Problem::checkEdit($c, $problem_id);
    }
    public static function checkDelete($c, $problem_id, $subtask_id) {
        return self::checkEdit($c, $problem_id, $subtask_id);
    }

    public static function getSubtaskById($c, $problem_id, $subtask_id) {
        $q = $c->qf->newSelect()
            ->cols(["id", "score"])
            ->from("subtasks")
            ->where("id = ?", $subtask_id)
            ->where("problem_id = ?", $problem_id);
        return $c->db->fetchOne($q->getStatement(), $q->getBindValues());
    }
    public static function getTestcaseExistsBySubtaskId($c, $subtask_id) {
        $q = $c->qf->newSelect()
            ->cols(["testcase_id", "exists"])
            ->from("subtask_testcases_view")
            ->where("subtask_id = ?", $subtask_id)
            ->orderBy(["testcase_id"]);
        return $c->db->fetchAssoc($q->getStatement(), $q->getBindValues());
    }
}
