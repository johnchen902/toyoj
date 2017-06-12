<?php
namespace Toyoj\Controllers;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
class Subtask {
    public static function showAll($c, Request $request, Response $response) {
        // TODO implement
        $problem_id = $request->getAttribute("problem_id");
        return Utilities::redirect($response, 302,
                $c->router->pathFor("problem", ["problem_id" => $problem_id]) . "#subtasks");
    }

    public static function show($c, Request $request, Response $response) {
        // TODO implement
        $problem_id = $request->getAttribute("problem_id");
        return Utilities::redirect($response, 302,
                $c->router->pathFor("problem", ["problem_id" => $problem_id]) . "#subtasks");
    }

    public static function showCreatePage($c, Request $request, Response $response) {
        $problem_id = $request->getAttribute("problem_id");
        $problem = Problem::getBaseProblem($c, $problem_id);
        if(!$problem)
            return ($c->errorview)($response, 404, "No Such Problem");
        if(!self::checkCreate($c, $problem_id))
            return ($c->errorview)($response, 403, "Forbidden");

        $testcases = Problem::getTestcasesByProblemId($c, $problem_id);
        $problem["testcases"] = $testcases;

        return $c->view->render($response, "subtask-new.html",
                ["problem" => $problem]);
    }
    public static function create($c, Request $request, Response $response) {
        return (new class extends AbstractPostHandler {
            protected function getAttributeNames() {
                return ["problem_id"];
            }
            protected function getFieldNames() {
                return ["score", "testcase_ids"];
            }
            protected function transformData(array &$data) {
                $data["score"] = (int) $data["score"];
                $data["testcase_ids"] = (array) $data["testcase_ids"];
            }
            protected function verifyData(array $data) {
                return Subtask::validateSubtask($data);
            }
            protected function checkPermissions($c, array $data) {
                return Subtask::checkCreate($c, $data["problem_id"]);
            }
            protected function getSuccessMessage() {
                return "Subtask Created";
            }
            protected function getSuccessLocation($c, array $data, $result) {
                return $c->router->pathFor("subtask",
                        ["problem_id" => $data["problem_id"], "subtask_id" => $result]);
            }
            protected function getErrorLocation($c, array $data, \Exception $e) {
                return $c->router->pathFor("subtask-new",
                        ["problem_id" => $data["problem_id"]]);
            }
            protected function transaction($c, array $data) {
                $q = $c->qf->newInsert()
                    ->into("subtasks")
                    ->cols([
                        "problem_id" => $data["problem_id"],
                        "score" => $data["score"],
                    ])
                    ->returning(["id"]);
                $id = $c->db->fetchValue($q->getStatement(), $q->getBindValues());

                $q = $c->qf->newUpdate()
                    ->table("subtask_testcases_view")
                    ->cols(["exists" => TRUE])
                    ->where("subtask_id = ?", $id)
                    ->where("testcase_id = ANY (ARRAY[?] :: integer[])",
                        $data["testcase_ids"]);
                $cnt = $c->db->fetchAffected($q->getStatement(), $q->getBindValues());
                if($cnt != count($data["testcase_ids"]))
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
        $problem_id = $request->getAttribute("problem_id");
        $problem = Problem::getBaseProblem($c, $problem_id);
        if(!$problem)
            return ($c->errorview)($response, 404, "No Such Problem");

        $subtask_id = $request->getAttribute("subtask_id");
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
            Utilities::errorMessage($c, "WTF neither delete nor update.");
            return Utilities::redirectRoute($response, 303, "subtask-edit", [
                "problem_id" => $request->getAttribute("problem_id"),
                "subtask_id" => $request->getAttribute("subtask_id"),
            ]);
        }
    }
    private static function edit($c, Request $request, Response $response) {
        return (new class extends AbstractPostHandler {
            protected function getAttributeNames() {
                return ["problem_id", "subtask_id"];
            }
            protected function getFieldNames() {
                return ["score", "testcase_ids"];
            }
            protected function transformData(array &$data) {
                $data["score"] = (int) $data["score"];
                $data["testcase_ids"] = (array) $data["testcase_ids"];
            }
            protected function verifyData(array $data) {
                return Subtask::validateSubtask($data);
            }
            protected function checkPermissions($c, array $data) {
                return Subtask::checkEdit($c, $data["problem_id"],
                        $data["subtask_id"]);
            }
            protected function getSuccessMessage() {
                return "Subtask Edited";
            }
            protected function getSuccessLocation($c, array $data, $result) {
                return $c->router->pathFor("subtask", [
                    "problem_id" => $data["problem_id"],
                    "subtask_id" => $data["subtask_id"],
                ]);
            }
            protected function getErrorLocation($c, array $data, \Exception $e) {
                return $c->router->pathFor("subtask-edit", [
                    "problem_id" => $data["problem_id"],
                    "subtask_id" => $data["subtask_id"],
                ]);
            }
            protected function transaction($c, array $data) {
                $q = $c->qf->newUpdate()
                    ->table("subtasks")
                    ->cols(["score" => $data["score"]])
                    ->where("id = ?", $data["subtask_id"])
                    ->where("problem_id = ?", $data["problem_id"]);
                $cnt = $c->db->fetchAffected($q->getStatement(), $q->getBindValues());
                if($cnt != 1)
                    throw new \Exception();

                $q = $c->qf->newUpdate()
                    ->table("subtask_testcases_view")
                    ->set("exists", "testcase_id = ANY (ARRAY[:testcases] :: integer[])")
                    ->where("subtask_id = ?", $data["subtask_id"])
                    ->bindValue("testcases", $data["testcase_ids"]);
                $c->db->perform($q->getStatement(), $q->getBindValues());
            }
        })->handle($c, $request, $response);
    }
    private static function delete($c, Request $request, Response $response) {
        return (new class extends AbstractPostHandler {
            protected function getAttributeNames() {
                return ["problem_id", "subtask_id"];
            }
            protected function checkPermissions($c, array $data) {
                return Subtask::checkDelete($c, $data["problem_id"],
                        $data["subtask_id"]);
            }
            protected function getSuccessMessage() {
                return "Subtask Deleted";
            }
            protected function getSuccessLocation($c, array $data, $result) {
                return $c->router->pathFor("subtask-list",
                        ["problem_id" => $data["problem_id"]]);
            }
            protected function getErrorLocation($c, array $data, \Exception $e) {
                return $c->router->pathFor("subtask-edit", [
                    "problem_id" => $data["problem_id"],
                    "subtask_id" => $data["subtask_id"],
                ]);
            }
            protected function transaction($c, array $data) {
                $q = $c->qf->newDelete()
                    ->from("subtasks")
                    ->where("problem_id = ?", $data["problem_id"])
                    ->where("id = ?", $data["subtask_id"]);
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
