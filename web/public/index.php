<?php
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

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
    $pdo = new PDO("pgsql:dbname=toyoj user=toyojweb");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
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

$app->get("/", function (Request $request, Response $response) {
    return $this->view->render($response, "index.html");
})->setName("index");

$app->get("/login", function (Request $request, Response $response) {
    return $this->view->render($response, "login.html");
})->setName("login");
$app->post("/login", function (Request $request, Response $response) {
    if($this->session["login"] ?? false) {
        $this->messages[] = "Already logged in";
        return redirect($response, 303, $this->router->pathFor("index"));
    }

    $username = $request->getParsedBodyParam("username");
    $password = $request->getParsedBodyParam("password");
    $stmt = $this->db->prepare("SELECT uid, hash FROM users JOIN passwords USING (uid) WHERE username=:username");
    $stmt->execute(array("username" => $username));
    $user = $stmt->fetch();
    if(!$user) {
        $this->messages[] = "Incorrect username or password";
        return redirect($response, 303, $this->router->pathFor("login"));
    }
    if(!password_verify($password, $user["hash"])) {
        $this->messages[] = "Incorrect username or password";
        return redirect($response, 303, $this->router->pathFor("login"));
    }

    $this->session["login"] = $user["uid"];

    $this->messages[] = "Logged in successfully";
    return redirect($response, 303, $this->router->pathFor("index"));
});

$app->get("/logout", function (Request $request, Response $response) {
    return $this->view->render($response, "logout.html");
})->setName("logout");

$app->post("/logout", function (Request $request, Response $response) {
    if(isset($this->session["login"])) {
        unset($this->session["login"]);
        $this->messages[] = "Logged out successfully";
    } else {
        $this->messages[] = "You are not logged in";
    }
    return redirect($response, 303, $this->router->pathFor("login"));
});

$app->get("/problems/", function (Request $request, Response $response) {
    $problems = $this->db->query("SELECT p.pid, p.title, p.create_date, p.manager, u.username AS manager_name, p.ready FROM problems AS p, users AS u WHERE p.manager = u.uid ORDER BY pid");
    return $this->view->render($response, "problems.html", array("problems" => $problems));
})->setName("problem-list");

$app->get("/problems/new", function (Request $request, Response $response) {
    return $response;
})->setName("new-problem");

$app->post("/problems/new", function (Request $request, Response $response) {
    return $response;
});

$app->get("/problems/{pid:[0-9]+}/", function (Request $request, Response $response, array $args) {
    $pid = $args["pid"];
    $problem = $this->db->prepare("SELECT p.pid, p.statement, p.title, p.create_date, p.manager, u.username AS manager_name, p.ready FROM problems AS p, users AS u WHERE p.manager = u.uid AND p.pid = :pid");
    $problem->execute(array(":pid" => $pid));
    $problem = $problem->fetch();
    if(!$problem) {
        return ($this->errorview)($response, 404, "No Such Problem");
    }
    $problem["cansubmit"] = $this->permissions->checkSubmit($pid);
    $problem["canedit"] = $this->permissions->checkEditProblem($pid);

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

    return $this->view->render($response, "problem.html",
        array(
            "problem" => $problem,
            "subtasks" => $subtasks,
            "testcases" => $testcases,
        )
    );
})->setName("problem");
$app->post("/problems/{pid:[0-9]+}/", function (Request $request, Response $response, array $args) {
    $pid = $args["pid"];
    $login = $this->session["login"] ?? false;
    $language = $request->getParsedBodyParam("language");
    $code = $request->getParsedBodyParam("code");
    $code = str_replace("\r\n", "\n", $code);

    if(!$code) {
        $this->messages[] = "Code is empty.";
        return redirect($response, 303, $this->router->pathFor("problem", array("pid" => $pid)));
    }
    if(strlen(code) > 65536) { // XXX magic number
        $this->messages[] = "Code must not be longer than 65536 bytes.";
        return redirect($response, 303, $this->router->pathFor("problem", array("pid" => $pid)));
    }

    $this->db->exec("BEGIN TRANSACTION ISOLATION LEVEL SERIALIZABLE");
    if(!$this->permissions->checkSubmit($pid)) {
        $this->db->exec("ROLLBACK");
        $this->messages[] = "You are not allowed to submit on this problem.";
        return redirect($response, 303, $this->router->pathFor("problem", array("pid" => $pid)));
    }
    $sid = $this->db->prepare("INSERT INTO submissions(pid, submitter, language, code) VALUES (:pid, :submitter, :language, :code) RETURNING sid");
    $sid->execute(array(":pid" => $pid, ":submitter" => $login, ":language" => $language, ":code" => $code));
    $sid = $sid->fetch();
    $this->db->exec("COMMIT");

    if(!$sid) {
        $this->messages[] = "Submission failed for unknown reason";
        return redirect($response, 303, $this->router->pathFor("submission-list"));
    }
    $sid = $sid["sid"];

    return redirect($response, 303, $this->router->pathFor("submission", array("sid" => $sid)));
});

$app->get("/problems/{pid:[0-9]+}/edit", function (Request $request, Response $response, array $args) {
    $pid = $args["pid"];
    $problem = $this->db->prepare("SELECT p.pid, p.statement, p.title, p.create_date, p.manager, u.username AS manager_name, p.ready FROM problems AS p, users AS u WHERE p.manager = u.uid AND p.pid = :pid");
    $problem->execute(array(":pid" => $pid));
    $problem = $problem->fetch();
    if(!$problem) {
        return ($this->errorview)($response, 404, "No Such Problem");
    }
    $problem["canedit"] = $this->permissions->checkEditProblem($pid);

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
})->setName("edit-problem");
$app->post("/problems/{pid:[0-9]+}/edit", function (Request $request, Response $response, array $args) {
    $pid = $args["pid"];
    $login = $this->session["login"];
    $title = $request->getParsedBodyParam("title");
    $statement = $request->getParsedBodyParam("statement");
    $ready = $request->getParsedBodyParam("ready");

    $statement = str_replace("\r\n", "\n", $statement);
    $ready = $ready === "ready" ? "t" : "f";

    if(!$title) {
        $this->messages[] = "Title is empty.";
        return redirect($response, 303, $this->router->pathFor("edit-problem", array("pid" => $pid)));
    }
    if(!$statement) {
        $this->messages[] = "Statement is empty.";
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

$app->get("/problems/{pid:[0-9]+}/tests/new", function (Request $request, Response $response, array $args) {
    $pid = $args["pid"];
    $problem = $this->db->prepare("SELECT pid, title, manager FROM problems WHERE pid = :pid");
    $problem->execute(array(":pid" => $pid));
    $problem = $problem->fetch();
    if(!$problem) {
        return ($this->errorview)($response, 404, "No Such Problem");
    }
    $problem["canaddtest"] = $this->permissions->checkNewTestCase($pid);
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

    $valid = true;
    if($time_limit < 100 || $time_limit > 15000) {
        $this->messages[] = "Time limit is out of range [100 ms, 15000 ms].";
        $valid = false;
    }
    if($memory_limit < 8192 || $memory_limit > 1048576) {
        $this->messages[] = "Memory limit is out of range [8192 KiB, 1048576 KiB].";
        $valid = false;
    }
    if(!$input) {
        $this->messages[] = "Input is empty.";
        $valid = false;
    } else if(strlen($input) > 16 << 20) {
        $this->messages[] = "Input is longer than 16 MiB.";
        $valid = false;
    }
    if(!$output) {
        $this->messages[] = "Output is empty.";
        $valid = false;
    } else if(strlen($output) > 16 << 20) {
        $this->messages[] = "Output is longer than 16 MiB.";
        $valid = false;
    }
    if(!$valid) {
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
    $testcase["canedit"] = $this->permissions->checkEditTestCase($pid, $testcaseid);

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
    $testcase["canedit"] = $this->permissions->checkEditTestCase($pid, $testcaseid);

    return $this->view->render($response, "testcase-edit.html",
        array(
            "testcase" => $testcase
        )
    );
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

    $valid = true;
    if($time_limit < 100 || $time_limit > 15000) {
        $this->messages[] = "Time limit is out of range [100 ms, 15000 ms].";
        $valid = false;
    }
    if($memory_limit < 8192 || $memory_limit > 1048576) {
        $this->messages[] = "Memory limit is out of range [8192 KiB, 1048576 KiB].";
        $valid = false;
    }
    if(!$input) {
        $this->messages[] = "Input is empty.";
        $valid = false;
    } else if(strlen($input) > 16 << 20) {
        $this->messages[] = "Input is longer than 16 MiB.";
        $valid = false;
    }
    if(!$output) {
        $this->messages[] = "Output is empty.";
        $valid = false;
    } else if(strlen($output) > 16 << 20) {
        $this->messages[] = "Output is longer than 16 MiB.";
        $valid = false;
    }
    if(!$valid) {
        return redirect($response, 303, $this->router->pathFor("edit-test", array("pid" => $pid, "testid" => $testid)));
    }

    $this->db->exec("BEGIN TRANSACTION ISOLATION LEVEL SERIALIZABLE");
    if(!$this->permissions->checkEditTestCase($pid, $testid)) {
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
    return $response;
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
