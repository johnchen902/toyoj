<?php
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use Aura\Sql\ExtendedPdo;
use Aura\SqlQuery\QueryFactory;

set_include_path(get_include_path() . PATH_SEPARATOR . "../classes/");
spl_autoload_register();
require "../vendor/autoload.php";

function redirect(Response $response, int $code, string $location) {
    $response = $response->withStatus($code);
    $response = $response->withHeader("Location", $location);
    return $response;
}

date_default_timezone_set("Asia/Taipei");

$config["displayErrorDetails"] = true;

$app = new \Slim\App(["settings" => $config]);
$container = $app->getContainer();
$container["view"] = function ($container) {
    $view = new \Slim\Views\Twig("../templates", [
    ]);
    $basePath = rtrim(str_ireplace("index.php", "", $container["request"]->getUri()->getBasePath()), "/");
    $view->addExtension(new Slim\Views\TwigExtension($container["router"], $basePath));
    $view->getEnvironment()->addFilter(new Twig_Filter('markdown', function($string) {
        return Parsedown::instance()->setMarkupEscaped(true)->text($string);
    }, array("is_safe" => array("html"))));
    $view["login"] = $container->session["login"] ?? 0;
    $view["messages"] = $container->messages;
    return $view;
};
$container["db"] = function ($container) {
    return new ExtendedPdo("pgsql:dbname=toyoj user=toyojweb");
};
$container["qf"] = function ($container) {
    return new QueryFactory("pgsql");
};
$container["errorview"] = function ($container) {
    return function (Response $response, int $status, string $message) use ($container) {
        $response = $response->withStatus($status);
        return $container->view->render($response, "error.html", array("status" => $status, "message" => $message));
    };
};
$container["notFoundHandler"] = function ($container) {
    return function (Request $request, Response $response) use ($container) {
        return ($container->errorview)($response, 404, "Not Found");
    };
};
$container["session"] = function ($container) {
    return new \Toyoj\SessionWrapper();
};
$container["messages"] = function ($container) {
    return new \Toyoj\MessageWrapper($container->session);
};
$container["permissions"] = function ($container) {
    return new \Toyoj\PermissionChecker($container);
};
$container["forms"] = function ($container) {
    return new \Toyoj\FormValidator();
};

$app->get("/", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Index::get($this, $request, $response);
})->setName("index");

$app->get("/login", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Login::get($this, $request, $response);
})->setName("login");
$app->post("/login", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Login::post($this, $request, $response);
});

$app->get("/logout", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Logout::get($this, $request, $response);
})->setName("logout");
$app->post("/logout", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Logout::post($this, $request, $response);
});

$app->get("/problems/", function (Request $request, Response $response) {
    return \Toyoj\Controllers\ProblemList::get($this, $request, $response);
})->setName("problem-list");

$app->get("/problems/new", function (Request $request, Response $response) {
    return \Toyoj\Controllers\ProblemNew::get($this, $request, $response);
})->setName("problem-new");
$app->post("/problems/new", function (Request $request, Response $response) {
    return \Toyoj\Controllers\ProblemNew::post($this, $request, $response);
});

$app->get("/problems/{pid:[0-9]+}/", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Problem::get($this, $request, $response);
})->setName("problem");
$app->post("/problems/{pid:[0-9]+}/", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Problem::post($this, $request, $response);
});

$app->get("/problems/{pid:[0-9]+}/edit", function (Request $request, Response $response, array $args) {
    $pid = $args["pid"];
    $problem = $this->db->prepare("SELECT p.pid, p.statement, p.title, p.create_date, p.manager, u.username AS manager_name, p.ready FROM problems AS p, users AS u WHERE p.manager = u.uid AND p.pid = :pid");
    $problem->execute(array(":pid" => $pid));
    $problem = $problem->fetch();
    if(!$problem) {
        return ($this->errorview)($response, 404, "No Such Problem");
    }
    if(!$this->permissions->checkEditProblem($pid)) {
        return $this->view->render($response, "problem-source.html",
            array("problem" => $problem));
    }

    $subtasks = $this->db->prepare("SELECT subtaskid, score, testcaseids FROM subtasks_view WHERE pid = :pid ORDER BY subtaskid");
    $subtasks->execute(array(":pid" => $pid));
    $subtasks = $subtasks->fetchAll();
    foreach($subtasks as &$subtask) {
        $tcids = array_map("intval", explode(",", substr($subtask["testcaseids"], 1, -1)));
        sort($tcids);
        $subtask["testcaseids"] = implode(", ", $tcids);
    }

    $testcases = $this->db->prepare("SELECT testcaseid, time_limit, memory_limit, checker FROM testcases WHERE pid = :pid ORDER BY testcaseid");
    $testcases->execute(array(":pid" => $pid));
    $testcases = $testcases->fetchAll();

    return $this->view->render($response, "problem-edit.html",
        array(
            "problem" => $problem,
            "subtasks" => $subtasks,
            "testcases" => $testcases,
        )
    );
})->setName("problem-edit");
$app->post("/problems/{pid:[0-9]+}/edit", function (Request $request, Response $response, array $args) {
    $pid = $args["pid"];
    $login = $this->session["login"];
    $title = $request->getParsedBodyParam("title");
    $statement = $request->getParsedBodyParam("statement");
    $ready = $request->getParsedBodyParam("ready");

    $statement = str_replace("\r\n", "\n", $statement);
    $ready = $ready === "ready" ? "t" : "f";

    $errors = $this->forms->validateProblem($title, $statement);
    if($errors) {
        foreach($errors as $e)
            $this->messages[] = $e;
        return redirect($response, 303, $this->router->pathFor("edit-problem", array("pid" => $pid)));
    }

    $this->db->exec("BEGIN TRANSACTION ISOLATION LEVEL SERIALIZABLE");
    if(!$this->permissions->checkEditProblem($pid)) {
        $this->db->exec("ROLLBACK");
        $this->messages[] = "You are not allowed to edit this problem.";
        return redirect($response, 303, $this->router->pathFor("problem", array("pid" => $pid)));
    }
    $stmt = $this->db->prepare("UPDATE problems SET (title, statement, ready) = (:title, :statement, :ready) WHERE pid = :pid");
    $stmt->execute(array(":title" => $title, ":statement" => $statement, ":ready" => $ready, ":pid" => $pid));
    $editSuccess = $stmt->rowCount() > 0;
    $this->db->exec("COMMIT");

    if(!$editSuccess) {
        $this->messages[] = "Edit failed for unknown reason";
        return redirect($response, 303, $this->router->pathFor("edit-problem", array("pid" => $pid)));
    }

    $this->messages[] = "Problem edited.";
    return redirect($response, 303, $this->router->pathFor("problem", array("pid" => $pid)));
});

$app->get("/problems/{pid:[0-9]+}/subtasks/", function (Request $request, Response $response, array $args) {
    $pid = $args["pid"];
    return redirect($response, 302, $this->router->pathFor("problem", ["pid" => $pid]) . "#subtasks");
})->setName("subtask-list");

$app->get("/problems/{pid:[0-9]+}/subtasks/new", function (Request $request, Response $response, array $args) {
    $pid = $args["pid"];
    $problem = $this->db->prepare("SELECT pid, title, manager FROM problems WHERE pid = :pid");
    $problem->execute([":pid" => $pid]);
    $problem = $problem->fetch();
    if(!$problem)
        return ($this->errorview)($response, 404, "No Such Problem");
    if(!$this->permissions->checkNewSubtask($pid))
        return ($this->errorview)($response, 403, "Forbidden");

    $testcases = $this->db->prepare("SELECT testcaseid FROM testcases WHERE pid = :pid");
    $testcases->execute([":pid" => $pid]);
    $testcases = $testcases->fetchAll();

    return $this->view->render($response, "subtask-new.html",
            ["problem" => $problem, "testcases" => $testcases]);
})->setName("new-subtask");
$app->post("/problems/{pid:[0-9]+}/subtasks/new", function (Request $request, Response $response, array $args) {
    $pid = $args["pid"];
    $login = $this->session["login"];
    $score = (int) $request->getParsedBodyParam("score");
    $testcaseids = $request->getParsedBodyParam("testcaseids");

    $errors = $this->forms->validateSubtask($score, $testcaseids, $testcaseids);
    if($errors) {
        foreach($errors as $e)
            $this->messages[] = $e;
        return redirect($response, 303, $this->router->pathFor("new-subtask", array("pid" => $pid)));
    }

    $this->db->exec("BEGIN TRANSACTION ISOLATION LEVEL SERIALIZABLE");
    if(!$this->permissions->checkNewSubtask($pid)) {
        $this->db->exec("ROLLBACK");
        $this->messages[] = "You are not allowed to add new subtask for this problem.";
        return redirect($response, 303, $this->router->pathFor("problem", array("pid" => $pid)));
    }
    $stmt = $this->db->prepare("SELECT 1 FROM testcases WHERE pid = :pid AND testcaseid = :testcaseid");
    foreach($testcaseids as $testcaseid) {
        $stmt->execute([":pid" => $pid, ":testcaseid" => $testcaseid]);
        if(!$stmt->fetch()) {
            $this->db->exec("ROLLBACK");
            $this->messages[] = "Test case #$testcaseid is not of problem #$pid";
            return redirect($response, 303, $this->router->pathFor("new-subtask", array("pid" => $pid)));
        }
    }

    $stmt = $this->db->prepare("INSERT INTO subtasks (pid, score) VALUES (:pid, :score) RETURNING subtaskid");
    $stmt->execute([":pid" => $pid, ":score" => $score ]);
    $subtaskid = $stmt->fetch()["subtaskid"];

    $stmt = $this->db->prepare("INSERT INTO subtasktestcases (subtaskid, testcaseid) VALUES (:subtaskid, :testcaseid)");
    foreach($testcaseids as $testcaseid) {
        $stmt->execute([":subtaskid" => $subtaskid, ":testcaseid" => $testcaseid]);
    }
    $this->db->exec("COMMIT");

    $this->messages[] = "Subtask added.";
    return redirect($response, 303, $this->router->pathFor("subtask", array("pid" => $pid, "subtaskid" => $subtaskid)));
});

$app->get("/problems/{pid:[0-9]+}/subtasks/{subtaskid:[0-9]+}/", function (Request $request, Response $response, array $args) {
    $pid = $args["pid"];
    return redirect($response, 302, $this->router->pathFor("problem", ["pid" => $pid]) . "#subtasks");
})->setName("subtask");

$app->get("/problems/{pid:[0-9]+}/subtasks/{subtaskid:[0-9]+}/edit", function (Request $request, Response $response, array $args) {
    $pid = $args["pid"];
    $subtaskid = $args["subtaskid"];
    $subtask = $this->db->prepare("SELECT p.pid, p.title, s.subtaskid, s.score FROM subtasks s JOIN problems p USING (pid) WHERE pid = :pid AND subtaskid = :subtaskid");
    $subtask->execute([":pid" => $pid, ":subtaskid" => $subtaskid]);
    $subtask = $subtask->fetch();
    if(!$subtask)
        return ($this->errorview)($response, 404, "No Such Problem Or Subtask");
    if(!$this->permissions->checkEditSubtask($subtaskid))
        return ($this->errorview)($response, 403, "Forbidden");

    $testcases = $this->db->prepare("SELECT testcaseid, testcaseid IN (SELECT testcaseid FROM subtasktestcases WHERE subtaskid = :subtaskid) AS included FROM testcases WHERE pid = :pid");
    $testcases->execute([":subtaskid" => $subtaskid, ":pid" => $pid]);
    $testcases = $testcases->fetchAll();

    return $this->view->render($response, "subtask-edit.html",
            ["subtask" => $subtask, "testcases" => $testcases]);
})->setName("edit-subtask");
$app->post("/problems/{pid:[0-9]+}/subtasks/{subtaskid:[0-9]+}/edit", function (Request $request, Response $response, array $args) {
    $pid = $args["pid"];
    $subtaskid = $args["subtaskid"];
    $login = $this->session["login"];
    $score = (int) $request->getParsedBodyParam("score");
    $testcaseids = $request->getParsedBodyParam("testcaseids");
    $alltestcaseids = $request->getParsedBodyParam("alltestcaseids");
    $delete = $request->getParsedBodyParam("delete");

    if($delete) {
        $this->db->exec("BEGIN TRANSACTION ISOLATION LEVEL SERIALIZABLE");
        if(!$this->permissions->checkDeleteSubtask($subtaskid)) {
            $this->db->exec("ROLLBACK");
            $this->messages[] = "You are not allowed to delete this subtask.";
            return redirect($response, 303, $this->router->pathFor("subtask", array("pid" => $pid, "subtaskid" => $subtaskid)));
        }
        $stmt = $this->db->prepare("DELETE FROM subtasks WHERE pid = :pid AND subtaskid = :subtaskid");
        $stmt->execute(["pid" => $pid, "subtaskid" => $subtaskid]);
        $success = $stmt->rowCount() > 0;
        if(!$success) {
            $this->db->exec("ROLLBACK");
            $this->messages[] = "Delete failed for unknown reason.";
            return redirect($response, 303, $this->router->pathFor("subtask", array("pid" => $pid, "subtaskid" => $subtaskid)));
        }
        $this->db->exec("COMMIT");
        return redirect($response, 303, $this->router->pathFor("subtask-list", array("pid" => $pid)));
    }

    $errors = $this->forms->validateSubtask($score, $testcaseids, $alltestcaseids);
    if($errors) {
        foreach($errors as $e)
            $this->messages[] = $e;
        return redirect($response, 303, $this->router->pathFor("edit-subtask", array("pid" => $pid, "subtaskid" => $subtaskid)));
    }

    $this->db->exec("BEGIN TRANSACTION ISOLATION LEVEL SERIALIZABLE");
    if(!$this->permissions->checkEditSubtask($subtaskid)) {
        $this->db->exec("ROLLBACK");
        $this->messages[] = "You are not allowed to edit this subtask.";
        return redirect($response, 303, $this->router->pathFor("subtask", array("pid" => $pid, "subtaskid" => $subtaskid)));
    }
    $stmt = $this->db->prepare("SELECT 1 FROM testcases WHERE pid = :pid AND testcaseid = :testcaseid");
    foreach($alltestcaseids as $testcaseid) {
        $stmt->execute([":pid" => $pid, ":testcaseid" => $testcaseid]);
        if(!$stmt->fetch()) {
            $this->db->exec("ROLLBACK");
            $this->messages[] = "Test case #$testcaseid is not of problem #$pid";
            return redirect($response, 303, $this->router->pathFor("edit-subtask", array("pid" => $pid, "subtaskid" => $subtaskid)));
        }
    }

    $stmt = $this->db->prepare("UPDATE subtasks SET score = :score WHERE pid = :pid AND subtaskid = :subtaskid");
    $stmt->execute([":subtaskid" => $subtaskid, ":score" => $score, ":pid" => $pid]);
    if($stmt->rowCount() == 0) {
        $this->db->exec("ROLLBACK");
        $this->messages[] = "Subtask #$subtaskid is not of problem #$pid";
        return redirect($response, 303, $this->router->pathFor("edit-subtask", array("pid" => $pid, "subtaskid" => $subtaskid)));
    }

    $stmtInsert = $this->db->prepare("INSERT INTO subtasktestcases (subtaskid, testcaseid) VALUES (:subtaskid, :testcaseid) ON CONFLICT DO NOTHING");
    $stmtDelete = $this->db->prepare("DELETE FROM subtasktestcases WHERE subtaskid = :subtaskid AND testcaseid = :testcaseid");
    foreach($alltestcaseids as $testcaseid) {
        if(in_array($testcaseid, $testcaseids))
            $stmtInsert->execute([":subtaskid" => $subtaskid, ":testcaseid" => $testcaseid]);
        else
            $stmtDelete->execute([":subtaskid" => $subtaskid, ":testcaseid" => $testcaseid]);
    }
    $this->db->exec("COMMIT");

    $this->messages[] = "Subtask edited.";
    return redirect($response, 303, $this->router->pathFor("subtask", array("pid" => $pid, "subtaskid" => $subtaskid)));
    return $response;
});

$app->get("/problems/{pid:[0-9]+}/tests/", function (Request $request, Response $response, array $args) {
    $pid = $args["pid"];
    return redirect($response, 302, $this->router->pathFor("problem", ["pid" => $pid]) . "#test-cases");
});

$app->get("/problems/{pid:[0-9]+}/tests/new", function (Request $request, Response $response, array $args) {
    $pid = $args["pid"];
    $problem = $this->db->prepare("SELECT pid, title, manager FROM problems WHERE pid = :pid");
    $problem->execute(array(":pid" => $pid));
    $problem = $problem->fetch();
    if(!$problem) {
        return ($this->errorview)($response, 404, "No Such Problem");
    }
    if(!$this->permissions->checkNewTestCase($pid)) {
        return ($this->errorview)($response, 403, "Forbidden");
    }
    return $this->view->render($response, "testcase-new.html",
            array("problem" => $problem));
})->setName("new-test");
$app->post("/problems/{pid:[0-9]+}/tests/new", function (Request $request, Response $response, array $args) {
    $pid = $args["pid"];
    $login = $this->session["login"];
    $time_limit = (int) $request->getParsedBodyParam("time_limit");
    $memory_limit = (int) $request->getParsedBodyParam("memory_limit");
    $checker = $request->getParsedBodyParam("checker");
    $input = $request->getParsedBodyParam("input");
    $output = $request->getParsedBodyParam("output");

    $input = str_replace("\r\n", "\n", $input);
    $output = str_replace("\r\n", "\n", $output);

    $errors = $this->forms->validateTestCase($time_limit, $memory_limit, $checker, $input, $output);
    if($errors) {
        foreach($errors as $e)
            $this->messages[] = $e;
        return redirect($response, 303, $this->router->pathFor("new-test", array("pid" => $pid)));
    }

    $this->db->exec("BEGIN TRANSACTION ISOLATION LEVEL SERIALIZABLE");
    if(!$this->permissions->checkNewTestCase($pid)) {
        $this->db->exec("ROLLBACK");
        $this->messages[] = "You are not allowed to add new test case for this problem.";
        return redirect($response, 303, $this->router->pathFor("problem", array("pid" => $pid)));
    }
    $stmt = $this->db->prepare("INSERT INTO testcases (pid, time_limit, memory_limit, checker, input, output) VALUES (:pid, :time_limit, :memory_limit, :checker, :input, :output) RETURNING testcaseid");
    $stmt->execute(array(
        ":pid" => $pid,
        ":time_limit" => $time_limit,
        ":memory_limit" => $memory_limit,
        ":checker" => $checker,
        ":input" => $input,
        ":output" => $output,
    ));
    $testid = $stmt->fetch();
    $this->db->exec("COMMIT");

    if(!$testid) {
        $this->messages[] = "Add new test case failed for unknown reason";
        return redirect($response, 303, $this->router->pathFor("new-test", array("pid" => $pid)));
    }

    $testid = $testid["testcaseid"];
    $this->messages[] = "Test case added.";
    return redirect($response, 303, $this->router->pathFor("test", array("pid" => $pid, "testid" => $testid)));
});

$app->get("/problems/{pid:[0-9]+}/tests/{testid:[0-9]+}/", function (Request $request, Response $response, array $args) {
    $pid = $args["pid"];
    $testcaseid = $args["testid"];

    $testcase = $this->db->prepare("SELECT p.pid, p.title, p.manager, t.testcaseid, t.time_limit, t.memory_limit, t.checker, t.input, t.output FROM problems p, testcases t WHERE p.pid = :pid AND p.pid = t.pid AND t.testcaseid = :testcaseid");
    $testcase->execute(array(":pid" => $pid, ":testcaseid" => $testcaseid));
    $testcase = $testcase->fetch();
    if(!$testcase) {
        return ($this->errorview)($response, 404, "No Such Problem Or Test Case");
    }
    $testcase["canedit"] = $this->permissions->checkEditTestCase($testcaseid);

    return $this->view->render($response, "testcase.html",
            array("testcase" => $testcase));
})->setName("test");

$app->get("/problems/{pid:[0-9]+}/tests/{testid:[0-9]+}/edit", function (Request $request, Response $response, array $args) {
    $pid = $args["pid"];
    $testcaseid = $args["testid"];

    $testcase = $this->db->prepare("SELECT p.pid, p.title, p.manager, t.testcaseid, t.time_limit, t.memory_limit, t.checker, t.input, t.output FROM problems p, testcases t WHERE p.pid = :pid AND p.pid = t.pid AND t.testcaseid = :testcaseid");
    $testcase->execute(array(":pid" => $pid, ":testcaseid" => $testcaseid));
    $testcase = $testcase->fetch();
    if(!$testcase) {
        return ($this->errorview)($response, 404, "No Such Problem Or Test Case");
    }
    if(!$this->permissions->checkEditTestCase($testcaseid)) {
        return ($this->errorview)($response, 403, "Forbidden");
    }

    return $this->view->render($response, "testcase-edit.html",
        array("testcase" => $testcase));
})->setName("edit-test");
$app->post("/problems/{pid:[0-9]+}/tests/{testid:[0-9]+}/edit", function (Request $request, Response $response, array $args) {
    $pid = $args["pid"];
    $testid = $args["testid"];
    $login = $this->session["login"];
    $time_limit = (int) $request->getParsedBodyParam("time_limit");
    $memory_limit = (int) $request->getParsedBodyParam("memory_limit");
    $checker = $request->getParsedBodyParam("checker");
    $input = $request->getParsedBodyParam("input");
    $output = $request->getParsedBodyParam("output");

    $input = str_replace("\r\n", "\n", $input);
    $output = str_replace("\r\n", "\n", $output);

    $errors = $this->forms->validateTestCase($time_limit, $memory_limit, $checker, $input, $output);
    if($errors) {
        foreach($errors as $e)
            $this->messages[] = $e;
        return redirect($response, 303, $this->router->pathFor("edit-test", array("pid" => $pid, "testid" => $testid)));
    }

    $this->db->exec("BEGIN TRANSACTION ISOLATION LEVEL SERIALIZABLE");
    if(!$this->permissions->checkEditTestCase($testid)) {
        $this->db->exec("ROLLBACK");
        $this->messages[] = "You are not allowed to edit this test case.";
        return redirect($response, 303, $this->router->pathFor("test", array("pid" => $pid, "testid" => $testid)));
    }
    $stmt = $this->db->prepare("UPDATE testcases SET (time_limit, memory_limit, checker, input, output) = (:time_limit, :memory_limit, :checker, :input, :output) WHERE testcaseid = :testcaseid AND pid = :pid");
    $stmt->execute(array(
        ":time_limit" => $time_limit,
        ":memory_limit" => $memory_limit,
        ":checker" => $checker,
        ":input" => $input,
        ":output" => $output,
        ":pid" => $pid,
        ":testcaseid" => $testid,
    ));
    $editSuccess = $stmt->rowCount() > 0;
    $this->db->exec("COMMIT");

    if(!$editSuccess) {
        $this->messages[] = "Edit failed for unknown reason.";
        return redirect($response, 303, $this->router->pathFor("edit-test", array("pid" => $pid, "testid" => $testid)));
    }

    $this->messages[] = "Test case edited.";
    return redirect($response, 303, $this->router->pathFor("test", array("pid" => $pid, "testid" => $testid)));
});

$app->get("/signup", function (Request $request, Response $response) {
    return $this->view->render($response, "signup.html");
})->setName("signup");
$app->post("/signup", function (Request $request, Response $response) {
    return redirect($response, 303, $this->router->pathFor("signup"));
});

$app->get("/submissions/", function (Request $request, Response $response) {
    $submissions = $this->db->query("SELECT sid, pid, title, submitter, submitter_name, accepted, rejected, minscore, maxscore, fullscore, time, memory, language, submit_time, judge_time FROM submissions_view ORDER BY sid DESC");
    return $this->view->render($response, "submissions.html", array("submissions" => $submissions));
})->setName("submission-list");

$app->get("/submissions/{sid:[0-9]+}/", function (Request $request, Response $response, array $args) {
    $sid = $args["sid"];
    $submission = $this->db->prepare("SELECT sid, pid, title, submitter, submitter_name, accepted, rejected, minscore, maxscore, fullscore, time, memory, language, code, submit_time, judge_time FROM submissions_view WHERE sid=:sid");
    $submission->execute(array("sid" => $sid));
    $submission = $submission->fetch();
    if(!$submission) {
        return ($this->errorview)($response, 404, "No Such Submission");
    }

    $subtasks = $this->db->prepare("SELECT subtaskid, minscore, maxscore, fullscore FROM subtask_results_view_2 WHERE sid=:sid ORDER BY subtaskid");
    $subtasks->execute(array("sid" => $sid));
    $subtasks = $subtasks->fetchAll();

    $testcases = $this->db->prepare("SELECT testcaseid, accepted, verdict, time, memory, judge_name, judge_time FROM results_view WHERE sid=:sid ORDER BY testcaseid");
    $testcases->execute(array("sid" => $sid));
    $testcases = $testcases->fetchAll();

    return $this->view->render($response, "submission.html", array("submission" => $submission, "subtasks" => $subtasks, "testcases" => $testcases));
})->setName("submission");

$app->get("/users/", function (Request $request, Response $response) {
    $users = $this->db->query("SELECT uid, username, register_date FROM users ORDER BY uid");
    return $this->view->render($response, "users.html", array("users" => $users));
})->setName("user-list");

$app->get("/users/{uid:[0-9]+}/", function (Request $request, Response $response, array $args) {
    $uid = $args["uid"];
    $stmt = $this->db->prepare("SELECT uid, username, register_date FROM users WHERE uid = :uid");
    $stmt->execute(array(":uid" => $uid));
    $user = $stmt->fetch();
    if(!$user) {
        return ($this->errorview)($response, 404, "No Such User");
    }
    return $this->view->render($response, "user.html", array("user" => $user));
})->setName("user");

$app->run();
