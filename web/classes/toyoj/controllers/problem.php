<?php
namespace Toyoj\Controllers;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use \redirect as redirect;
class Problem {
    public static function get($c, Request $request, Response $response) {
        $pid = $request->getAttribute("pid");
        $problem = $c->db->prepare("SELECT p.id, p.statement, p.title, p.create_time, p.manager_id, u.username AS manager_name, p.ready FROM problems AS p, users AS u WHERE p.manager_id = u.id AND p.id = :pid");
        $problem->execute([":pid" => $pid]);
        $problem = $problem->fetch();
        if(!$problem) {
            return ($c->errorview)($response, 404, "No Such Problem");
        }
        $problem["cansubmit"] = $c->permissions->checkSubmit($pid);
        $problem["canedit"] = $c->permissions->checkEditProblem($pid);

        $subtasks = $c->db->prepare("SELECT id, score FROM subtasks WHERE problem_id = :pid ORDER BY id");
        $subtasks->execute(array(":pid" => $pid));
        $subtasks = $subtasks->fetchAll();

        $testcases = $c->db->prepare("SELECT id, time_limit, memory_limit, checker_name FROM testcases WHERE problem_id = :pid ORDER BY id");
        $testcases->execute(array(":pid" => $pid));
        $testcases = $testcases->fetchAll();

        foreach($subtasks as &$subtask) {
            $subtask["testcase_ids"] = "under construction";
        }
        foreach($testcases as &$testcase) {
            $testcase["subtask_ids"] = "under construction";
        }

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
        if(!$c->permissions->checkSubmit($pid)) {
            $c->db->exec("ROLLBACK");
            $c->messages[] = "You are not allowed to submit on this problem.";
            return redirect($response, 303, 
                $c->router->pathFor("problem", ["pid" => $pid]));
        }
        $sid = $c->db->prepare("INSERT INTO submissions(problem_id, submitter_id, language_name, code) VALUES (:pid, :submitter, :language, :code) RETURNING id");
        $sid->execute([":pid" => $pid, ":submitter" => $login, ":language" => $language, ":code" => $code]);
        $sid = $sid->fetch();
        $c->db->exec("COMMIT");

        if(!$sid) {
            $c->messages[] = "Submission failed for unknown reason";
            return redirect($response, 303, $c->router->pathFor("submission-list"));
        }
        $sid = $sid["id"];

        return redirect($response, 303, $c->router->pathFor("submission", array("sid" => $sid)));
    }
};
?>
