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
    return \Toyoj\Controllers\Problem::showAll($this, $request, $response);
})->setName("problem-list");

$app->get("/problems/new", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Problem::showCreatePage($this, $request, $response);
})->setName("problem-new");
$app->post("/problems/new", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Problem::create($this, $request, $response);
});

$app->get("/problems/{pid:[0-9]+}/", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Problem::show($this, $request, $response);
})->setName("problem");
$app->post("/problems/{pid:[0-9]+}/", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Problem::submit($this, $request, $response);
});

$app->get("/problems/{pid:[0-9]+}/edit", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Problem::showEditPage($this, $request, $response);
})->setName("problem-edit");
$app->post("/problems/{pid:[0-9]+}/edit", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Problem::edit($this, $request, $response);
});

$app->get("/problems/{pid:[0-9]+}/subtasks/", function (Request $request, Response $response) {
    $pid = $request->getAttribute("pid");
    return redirect($response, 302, $this->router->pathFor("problem", ["pid" => $pid]) . "#subtasks");
})->setName("subtask-list");

$app->get("/problems/{pid:[0-9]+}/subtasks/new", function (Request $request, Response $response) {
    return \Toyoj\Controllers\SubtaskNew::get($this, $request, $response);
})->setName("subtask-new");
$app->post("/problems/{pid:[0-9]+}/subtasks/new", function (Request $request, Response $response) {
    return \Toyoj\Controllers\SubtaskNew::post($this, $request, $response);
});

$app->get("/problems/{pid:[0-9]+}/subtasks/{subtaskid:[0-9]+}/", function (Request $request, Response $response) {
    $pid = $request->getAttribute("pid");
    return redirect($response, 302, $this->router->pathFor("problem", ["pid" => $pid]) . "#subtasks");
})->setName("subtask");

$app->get("/problems/{pid:[0-9]+}/subtasks/{subtaskid:[0-9]+}/edit", function (Request $request, Response $response) {
    return \Toyoj\Controllers\SubtaskEdit::get($this, $request, $response);
})->setName("subtask-edit");
$app->post("/problems/{pid:[0-9]+}/subtasks/{subtaskid:[0-9]+}/edit", function (Request $request, Response $response) {
    return \Toyoj\Controllers\SubtaskEdit::post($this, $request, $response);
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
})->setName("test-new");
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
})->setName("test-edit");
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
