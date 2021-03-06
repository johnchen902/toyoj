<?php
namespace Toyoj\Controllers;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
class TestCase {
    public static function showAll($c, Request $request, Response $response) {
        // TODO implement
        $problem_id = $request->getAttribute("problem_id");
        return Utilities::redirect($response, 302,
                $c->router->pathFor("problem", ["problem_id" => $problem_id]) .
                    "#testcases");
    }

    public static function show($c, Request $request, Response $response) {
        $problem_id = $request->getAttribute("problem_id");
        $problem = Problem::getBaseProblem($c, $problem_id);
        if(!$problem)
            return ($c->errorview)($response, 404, "No Such Problem");

        $testcase_id = $request->getAttribute("testcase_id");
        $testcase = self::getTestCaseById($c, $problem_id, $testcase_id);
        if(!$testcase)
            return ($c->errorview)($response, 404, "No Such Test Case");

        $testcase["problem"] = $problem;
        $testcase["canedit"] = self::checkEdit($c, $problem_id, $testcase_id);
        return $c->view->render($response, "testcase.html",
                ["testcase" => $testcase]);
    }

    public static function showCreatePage($c, Request $request, Response $response) {
        $problem_id = $request->getAttribute("problem_id");
        $problem = Problem::getBaseProblem($c, $problem_id);
        if(!$problem)
            return ($c->errorview)($response, 404, "No Such Problem");
        if(!self::checkCreate($c, $problem_id))
            return ($c->errorview)($response, 403, "Forbidden");

        $subtasks = Problem::getSubtasksByProblemId($c, $problem_id);
        $problem["subtasks"] = $subtasks;

        return $c->view->render($response, "testcase-new.html",
                ["problem" => $problem]);
    }
    public static function create($c, Request $request, Response $response) {
        return (new class extends AbstractPostHandler {
            protected function getAttributeNames() {
                return ["problem_id"];
            }
            protected function getFieldNames() {
                return ["time_limit", "memory_limit", "checker",
                    "input", "output", "subtask_ids"];
            }
            protected function transformData(array &$data) {
                $data["time_limit"] = (int) $data["time_limit"];
                $data["memory_limit"] = (int) $data["memory_limit"];
                $data["input"] = Utilities::crlf2lf($data["input"]);
                $data["output"] = Utilities::crlf2lf($data["output"]);
                $data["testcase_ids"] = (array) $data["testcase_ids"];
            }
            protected function verifyData(array $data) {
                return TestCase::validateTestCase($data);
            }
            protected function checkPermissions($c, array $data) {
                return TestCase::checkCreate($c, $data["problem_id"]);
            }
            protected function getSuccessMessage() {
                return "Test Case Created";
            }
            protected function getSuccessLocation($c, array $data, $result) {
                return $c->router->pathFor("testcase",
                        ["problem_id" => $data["problem_id"], "testcase_id" => $result]);
            }
            protected function getErrorLocation($c, array $data, \Exception $e) {
                return $c->router->pathFor("testcase-new",
                        ["problem_id" => $data["problem_id"]]);
            }
            protected function transaction($c, array $data) {
                $q = $c->qf->newInsert()
                    ->into("testcases")
                    ->cols([
                        "problem_id" => $data["problem_id"],
                        "time_limit" => $data["time_limit"],
                        "memory_limit" => $data["memory_limit"],
                        "checker_name" => $data["checker"],
                        "input" => $data["input"],
                        "output" => $data["output"],
                    ])
                    ->returning(["id"]);
                $id = $c->db->fetchValue($q->getStatement(), $q->getBindValues());

                $q = $c->qf->newUpdate()
                    ->table("subtask_testcases_view")
                    ->cols(["exists" => TRUE])
                    ->where("testcase_id = ?", $id)
                    ->where("subtask_id = ANY (ARRAY[?] :: integer[])",
                        $data["subtask_ids"]);
                $cnt = $c->db->fetchAffected($q->getStatement(), $q->getBindValues());
                if($cnt != count($data["subtask_ids"]))
                    throw new \Exception();

                return $id;
            }
        })->handle($c, $request, $response);
    }
    public static function checkCreate($c, $problem_id) {
        return Problem::checkEdit($c, $problem_id);
    }
    public static function validateTestCase($data) {
        $e = [];
        if($data["time_limit"] < 100 || $data["time_limit"] > 15000)
            $e[] = "Time limit is out of range [100 ms, 15000 ms].";
        if($data["memory_limit"] < 8192 || $data["memory_limit"] > 1048576)
            $e[] = "Memory limit is out of range [8192 KiB, 1048576 KiB].";
        Utilities::validateString($e, $data["checker"], "Checker", 1, 32);
        Utilities::validateString($e, $data["input"], "Input", 1, 16 << 20);
        Utilities::validateString($e, $data["output"], "Output", 1, 16 << 20);
        return $e;
    }

    public static function showEditPage($c, Request $request, Response $response) {
        $problem_id = $request->getAttribute("problem_id");
        $problem = Problem::getBaseProblem($c, $problem_id);
        if(!$problem)
            return ($c->errorview)($response, 404, "No Such Problem");

        $testcase_id = $request->getAttribute("testcase_id");
        $testcase = self::getTestCaseById($c, $problem_id, $testcase_id);
        if(!$testcase)
            return ($c->errorview)($response, 404, "No Such Test Case");

        if(!self::checkEdit($c, $problem_id, $testcase_id))
            return ($c->errorview)($response, 403, "Forbidden");

        $subtasks = self::getSubtaskExistsByTestcaseId($c, $testcase_id);

        $testcase["problem"] = $problem;
        $testcase["subtask_exists"] = $subtasks;

        return $c->view->render($response, "testcase-edit.html",
                ["testcase" => $testcase]);
    }
    public static function editOrDelete($c, Request $request, Response $response) {
        if($request->getParsedBodyParam("delete") === "delete") {
            return self::delete($c, $request, $response);
        } else if($request->getParsedBodyParam("update") === "update") {
            return self::edit($c, $request, $response);
        } else {
            Utilities::errorMessage($c, "WTF neither delete nor update.");
            return Utilities::redirectRoute($response, 303, "testcase-edit", [
                "problem_id" => $request->getAttribute("problem_id"),
                "subtask_id" => $request->getAttribute("subtask_id"),
            ]);
        }
    }
    private static function edit($c, Request $request, Response $response) {
        return (new class extends AbstractPostHandler {
            protected function getAttributeNames() {
                return ["problem_id", "testcase_id"];
            }
            protected function getFieldNames() {
                return ["time_limit", "memory_limit", "checker",
                    "input", "output", "subtask_ids"];
            }
            protected function transformData(array &$data) {
                $data["time_limit"] = (int) $data["time_limit"];
                $data["memory_limit"] = (int) $data["memory_limit"];
                $data["input"] = Utilities::crlf2lf($data["input"]);
                $data["output"] = Utilities::crlf2lf($data["output"]);
                $data["testcase_ids"] = (array) $data["testcase_ids"];
            }
            protected function verifyData(array $data) {
                return TestCase::validateTestCase($data);
            }
            protected function checkPermissions($c, array $data) {
                return TestCase::checkEdit($c, $data["problem_id"],
                        $data["testcase_id"]);
            }
            protected function getSuccessMessage() {
                return "Test Case Edited";
            }
            protected function getSuccessLocation($c, array $data, $result) {
                return $c->router->pathFor("testcase", [
                    "problem_id" => $data["problem_id"],
                    "testcase_id" => $data["testcase_id"],
                ]);
            }
            protected function getErrorLocation(
                    $c, array $data, \Exception $e) {
                return $c->router->pathFor("testcase-edit", [
                    "problem_id" => $data["problem_id"],
                    "testcase_id" => $data["testcase_id"],
                ]);
            }
            protected function transaction($c, array $data) {
                $q = $c->qf->newUpdate()
                    ->table("testcases")
                    ->cols([
                        "time_limit" => $data["time_limit"],
                        "memory_limit" => $data["memory_limit"],
                        "checker_name" => $data["checker"],
                        "input" => $data["input"],
                        "output" => $data["output"],
                    ])
                    ->where("id = ?", $data["testcase_id"])
                    ->where("problem_id = ?", $data["problem_id"]);
                $cnt = $c->db->fetchAffected($q->getStatement(), $q->getBindValues());
                if($cnt != 1)
                    throw new \Exception();

                $q = $c->qf->newUpdate()
                    ->table("subtask_testcases_view")
                    ->set("exists",
                            "subtask_id = ANY (ARRAY[:subtasks] :: integer[])")
                    ->where("testcase_id = ?", $data["testcase_id"])
                    ->bindValue("subtasks", $data["subtask_ids"]);
                $c->db->perform($q->getStatement(), $q->getBindValues());
            }
        })->handle($c, $request, $response);
    }
    private static function delete($c, Request $request, Response $response) {
        return (new class extends AbstractPostHandler {
            protected function getAttributeNames() {
                return ["problem_id", "testcase_id"];
            }
            protected function checkPermissions($c, array $data) {
                return TestCase::checkDelete($c, $data["problem_id"],
                        $data["testcase_id"]);
            }
            protected function getSuccessMessage() {
                return "Test Case Deleted";
            }
            protected function getSuccessLocation($c, array $data, $result) {
                return $c->router->pathFor("testcase-list",
                        ["problem_id" => $data["problem_id"]]);
            }
            protected function getErrorLocation($c, array $data, \Exception $e) {
                return $c->router->pathFor("testcase-edit", [
                    "problem_id" => $data["problem_id"],
                    "testcase_id" => $data["testcase_id"],
                ]);
            }
            protected function transaction($c, array $data) {
                $q = $c->qf->newDelete()
                    ->from("testcases")
                    ->where("problem_id = ?", $data["problem_id"])
                    ->where("id = ?", $data["testcase_id"]);
                $cnt = $c->db->fetchAffected($q->getStatement(), $q->getBindValues());
                if($cnt != 1)
                    throw new \Exception();
            }
        })->handle($c, $request, $response);
    }
    public static function checkEdit($c, $problem_id, $testcase_id) {
        return Problem::checkEdit($c, $problem_id);
    }
    public static function checkDelete($c, $problem_id, $testcase_id) {
        return self::checkEdit($c, $problem_id, $testcase_id);
    }

    public static function getTestcaseById($c, $problem_id, $testcase_id) {
        $q = $c->qf->newSelect()
            ->cols([
                "id",
                "problem_id",
                "time_limit",
                "memory_limit",
                "checker_name",
                "input",
                "output",
            ])
            ->from("testcases")
            ->where("id = ?", $testcase_id)
            ->where("problem_id = ?", $problem_id);
        return $c->db->fetchOne($q->getStatement(), $q->getBindValues());
    }
    public static function getSubtaskExistsByTestcaseId($c, $testcase_id) {
        $q = $c->qf->newSelect()
            ->cols(["subtask_id", "exists"])
            ->from("subtask_testcases_view")
            ->where("testcase_id = ?", $testcase_id)
            ->orderBy(["subtask_id"]);
        return $c->db->fetchAssoc($q->getStatement(), $q->getBindValues());
    }
}
