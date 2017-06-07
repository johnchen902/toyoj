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
        $pid = $request->getAttribute("pid");
        try {
            $submission_id = self::doSubmit($c, $request);
        } catch(\Exception $e) {
            $c->messages[] = $e->getMessage() ?: "Unknown Error.";
            return Utilities::redirectRoute($response, 303, $c,
                    "problem", ["pid" => $pid]);
        }
        $c->messages[] = "Submitted.";
        return Utilities::redirectRoute($response, 303, $c,
                "submissions", ["sid" => $submission_id]);
    }
    private static function doSubmit($c, Request $request) {
        $language = $request->getParsedBodyParam("language");
        $code     = $request->getParsedBodyParam("code");

        $code = Utilities::crlf2lf($code);

        $errors = [];
        self::validateSubmission($errors, $language, $code);
        if($errors)
            throw new \Exception(join(" ", $errors));

        $pid = $request->getAttribute("pid");
        return Utilities::transactional($c, function () use (
                $c, $pid, $login, $language, $code) {
            if(!self::checkSubmit($c, $pid))
                throw new \Exception("Permission denied.");

            $q = $c->qf->newInsert()
                ->into("submissions")
                ->cols([
                    "problem_id" => $pid,
                    "submitter_id" => $c->session["login"],
                    "language_name" => $language,
                    "code" => $code,
                ])
                ->returning(["id"]);
            return $c->db->fetchValue($q->getStatement(), $q->getBindValues());
        });
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
        $ok = $c->db->fetchValue($q->getStatement(), $q->getBindValues());
        return boolval($ok);
    }
    public static function validateSubmission(array &$e, $lang, $code) {
        Utilities::validateString($e, $lang, "Language", 1, 32);
        Utilities::validateString($e, $code, "Code", 1, 65536);
    }

    public static function showCreatePage(
            $c, Request $request, Response $response) {
        if(!self::checkCreate($c))
            return ($c->errorview)($response, 403, "Forbidden");
        return $c->view->render($response, "problem-new.html");
    }
    public static function create($c, Request $request, Response $response) {
        try {
            $problem_id = self::doCreate($c, $request);
        } catch (\Exception $e) {
            $c->messages[] = $e->getMessage() ?: "Unknown Error.";
            return Utilities::redirectRoute($response, 303, $c,
                    "problem-new");
        }
        $c->messages[] = "New problem created.";
        return Utilities::redirectRoute($response, 303, $c,
                "problem", ["pid" => $problem_id]);
    }
    private static function doCreate($c, Request $request) {
        $title     = $request->getParsedBodyParam("title");
        $statement = $request->getParsedBodyParam("statement");

        $statement = Utilities::crlf2lf($statement);

        $errors = [];
        self::validateProblem($errors, $title, $statement);
        if($errors)
            throw new \Exception(join(" ", $errors));

        return Utilities::transactional($c, function () use (
                $c, $title, $statement) {
            if(!self::checkCreate($c))
                throw new \Exception("Permission denied.");
            $login = $c->session["login"];
            $q = $c->qf->newInsert()
                ->into("problems")
                ->cols([
                    "title" => $title,
                    "statement" => $statement,
                    "manager_id" => $login,
                    "ready" => false,
                ])
                ->returning(["id"]);
            return $c->db->fetchValue($q->getStatement(), $q->getBindValues());
        });
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
        $pid = $request->getAttribute("pid");
        try {
            self::doEdit($c, $request);
        } catch (\Exception $e) {
            $c->messages[] = $e->getMessage() ?: "Unknown Error.";
            return Utilities::redirectRoute($response, 303, $c,
                    "problem-edit", ["pid" => $pid]);
        }
        $c->messages[] = "Problem edited.";
        return Utilities::redirectRoute($response, 303, $c,
                "problem", ["pid" => $pid]);
    }
    private static function doEdit($c, Request $request) {
        $title     = $request->getParsedBodyParam("title");
        $statement = $request->getParsedBodyParam("statement");
        $ready     = $request->getParsedBodyParam("ready");

        $statement = Utilities::crlf2lf($statement);
        $ready = $ready === "ready";

        $errors = [];
        self::validateProblem($errors, $title, $statement);
        if($errors)
            throw new \Exception(join(" ", $errors));

        $pid = $request->getAttribute("pid");
        return Utilities::transactional($c, function () use (
                $c, $pid, $title, $statement, $ready) {
            if(!self::checkEdit($c, $pid))
                throw new \Exception("Permission denied.");
            $q = $c->qf->newUpdate()
                ->table("problems")
                ->cols([
                    "title" => $title,
                    "statement" => $statement,
                    "ready" => $ready,
                ])
                ->where("id = ?", $pid);
            $stmt = $q->getStatement();
            $bind = $q->getBindValues();
            if($c->db->fetchAffected($stmt, $bind) != 1)
                throw new \Exception();
        });
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
    public static function validateProblem(array &$e, $title, $statement) {
        Utilities::validateString($e, $title, "Title", 1, 128);
        Utilities::validateString($e, $statement, "Statement", 1, 65536);
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
