<?php
namespace Toyoj\Controllers;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
class TestCase {
    public static function showAll($c, Request $request, Response $response) {
        // TODO implement
        $problem_id = $request->getAttribute("pid");
        return Utilities::redirect($response, 302,
                $c->router->pathFor("problem", ["pid" => $problem_id]) .
                    "#test-cases");
    }

    public static function show($c, Request $request, Response $response) {
        $problem_id = $request->getAttribute("pid");
        $problem = Problem::getBaseProblem($c, $problem_id);
        if(!$problem)
            return ($c->errorview)($response, 404, "No Such Problem");

        $testcase_id = $request->getAttribute("testid");
        $testcase = self::getTestCaseById($c, $problem_id, $testcase_id);
        if(!$testcase)
            return ($c->errorview)($response, 404, "No Such Test Case");

        $testcase["problem"] = $problem;
        $testcase["canedit"] = self::checkEdit($c, $problem_id, $testcase_id);
        return $c->view->render($response, "testcase.html",
                ["testcase" => $testcase]);
    }

    public static function showCreatePage($c, Request $request, Response $response) {
        $problem_id = $request->getAttribute("pid");
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
                return ["pid"];
            }
            protected function getFieldNames() {
                return ["time_limit", "memory_limit", "checker",
                    "input", "output", "subtaskids"];
            }
            protected function transformData(array &$data) {
                $data["time_limit"] = (int) $data["time_limit"];
                $data["memory_limit"] = (int) $data["memory_limit"];
                $data["input"] = Utilities::crlf2lf($data["input"]);
                $data["output"] = Utilities::crlf2lf($data["output"]);
                $data["testcaseids"] = (array) $data["testcaseids"];
            }
            protected function verifyData(array $data) {
                return TestCase::validateTestCase($data);
            }
            protected function checkPermissions($c, array $data) {
                return TestCase::checkCreate($c, $data["pid"]);
            }
            protected function getSuccessMessage() {
                return "Test Case Created";
            }
            protected function getSuccessLocation($c, array $data, $result) {
                return $c->router->pathFor("test",
                        ["pid" => $data["pid"], "testid" => $result]);
            }
            protected function getErrorLocation($c, array $data, \Exception $e) {
                return $c->router->pathFor("test-new",
                        ["pid" => $data["pid"]]);
            }
            protected function transaction($c, array $data) {
                $q = $c->qf->newInsert()
                    ->into("testcases")
                    ->cols([
                        "problem_id" => $data["pid"],
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
                        $data["subtaskids"]);
                $cnt = $c->db->fetchAffected($q->getStatement(), $q->getBindValues());
                if($cnt != count($data["subtaskids"]))
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
        $problem_id = $request->getAttribute("pid");
        $problem = Problem::getBaseProblem($c, $problem_id);
        if(!$problem)
            return ($c->errorview)($response, 404, "No Such Problem");

        $testcase_id = $request->getAttribute("testid");
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
            $c->messages[] = "WTF neither delete nor update.";
            return Utilities::redirectRoute($response, 303, "test-edit", [
                "pid" => $request->getAttribute("pid"),
                "subtaskid" => $request->getAttribute("subtaskid"),
            ]);
        }
    }
    public static function edit($c, Request $request, Response $response) {
        return (new class extends AbstractPostHandler {
            protected function getAttributeNames() {
                return ["pid", "testid"];
            }
            protected function getFieldNames() {
                return ["time_limit", "memory_limit", "checker",
                    "input", "output", "subtaskids"];
            }
            protected function transformData(array &$data) {
                $data["time_limit"] = (int) $data["time_limit"];
                $data["memory_limit"] = (int) $data["memory_limit"];
                $data["input"] = Utilities::crlf2lf($data["input"]);
                $data["output"] = Utilities::crlf2lf($data["output"]);
                $data["testcaseids"] = (array) $data["testcaseids"];
            }
            protected function verifyData(array $data) {
                return TestCase::validateTestCase($data);
            }
            protected function checkPermissions($c, array $data) {
                return TestCase::checkEdit($c, $data["pid"],
                        $data["testid"]);
            }
            protected function getSuccessMessage() {
                return "Test Case Edited";
            }
            protected function getSuccessLocation($c, array $data, $result) {
                return $c->router->pathFor("test", [
                    "pid" => $data["pid"],
                    "testid" => $data["testid"],
                ]);
            }
            protected function getErrorLocation(
                    $c, array $data, \Exception $e) {
                return $c->router->pathFor("test-edit", [
                    "pid" => $data["pid"],
                    "testid" => $data["testid"],
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
                    ->where("id = ?", $data["testid"])
                    ->where("problem_id = ?", $data["pid"]);
                $cnt = $c->db->fetchAffected($q->getStatement(), $q->getBindValues());
                if($cnt != 1)
                    throw new \Exception();

                $q = $c->qf->newUpdate()
                    ->table("subtask_testcases_view")
                    ->set("exists",
                            "subtask_id = ANY (ARRAY[:subtasks] :: integer[])")
                    ->where("testcase_id = ?", $data["testid"])
                    ->bindValue("subtasks", $data["subtaskids"]);
                $c->db->perform($q->getStatement(), $q->getBindValues());
            }
        })->handle($c, $request, $response);
    }
    public static function delete($c, Request $request, Response $response) {
        // TODO
        return (new class extends AbstractPostHandler {
            protected function getAttributeNames() {
                return ["pid", "subtaskid"];
            }
            protected function checkPermissions($c, array $data) {
                return TestCase::checkDelete($c, $data["pid"],
                        $data["subtask_id"]);
            }
            protected function getSuccessMessage() {
                return "Test Case Deleted";
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
                $cnt = $c->db->fetchAffected(
                        $q->getStatement(), $q->getBindValues());
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
