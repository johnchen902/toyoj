<?php
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

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
        return Parsedown::instance()->text($string);
    }, array("pre_escape" => "html", "is_safe" => array("html"))));
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
    session_start();
    class SessionWrapper implements ArrayAccess {
        public function offsetExists($offset) {
            return isset($_SESSION[$offset]);
        }
        public function &offsetGet($offset) {
            return $_SESSION[$offset];
        }
        public function offsetSet($offset, $value) {
            if(is_null($offset))
                $_SESSION[] = $value;
            else
                $_SESSION[$offset] = $value;
        }
        public function offsetUnset($offset) {
            unset($_SESSION[$offset]);
        }
    };
    return new SessionWrapper();
};
$container["messages"] = function ($container) {
    class MessageWrapper implements Iterator, ArrayAccess {
        public function __construct($session) {
            $this->session = $session;
        }

        public function current() {
            return $this->session["messages"][0];
        }
        public function key() {
            return 0;
        }
        public function next() {
            array_shift($this->session["messages"]);
        }
        public function rewind() {
            // No-op
        }
        public function valid() {
            return isset($this->session["messages"][0]);
        }


        public function offsetExists($offset) {
            return isset($this->session["messages"][$offset]);
        }
        public function &offsetGet($offset) {
            return $this->session["messages"][$offset];
        }
        public function offsetSet($offset, $value) {
            if(is_null($offset))
                $this->session["messages"][] = $value;
            else
                $this->session["messages"][$offset] = $value;
        }
        public function offsetUnset($offset) {
            unset($this->session["messages"][$offset]);
        }
    };
    $session = $container->session;
    if(!isset($session["messages"]))
        $session["messages"] = array();
    return new MessageWrapper($session);
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

$app->get("/problems/{pid:[0-9]+}/", function (Request $request, Response $response, array $args) {
    $pid = $args["pid"];
    $problem = $this->db->prepare("SELECT p.pid, p.statement, p.title, p.create_date, p.manager, u.username AS manager_name, p.ready FROM problems AS p, users AS u WHERE p.manager = u.uid AND p.pid = :pid");
    $problem->execute(array(":pid" => $pid));
    $problem = $problem->fetch();
    if(!$problem) {
        return ($this->errorview)($response, 404, "No Such Problem");
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

    return $this->view->render($response, "problem.html",
        array(
            "problem" => $problem,
            "subtasks" => $subtasks,
            "testcases" => $testcases,
        )
    );
})->setName("problem");
$app->post("/problems/{pid:[0-9]+}/", function (Request $request, Response $response, array $args) {
    // TODO implement submit
    $this->messages[] = "Submit is not implemented";
    $pid = $args["pid"];
    return redirect($response, 303, $this->router->pathFor("problem", array("pid" => $pid)));
});

$app->get("/problems/new", function (Request $request, Response $response) {
    return $response;
})->setName("new-problem");

$app->post("/problems/new", function (Request $request, Response $response) {
    return $response;
});

$app->get("/problems/{pid:[0-9]+}/edit", function (Request $request, Response $response) {
    return $response;
})->setName("edit-problem");

$app->post("/problems/{pid:[0-9]+}/edit", function (Request $request, Response $response) {
    return $response;
});

$app->get("/problems/{pid:[0-9]+}/tests/", function (Request $request, Response $response) {
    return $response;
})->setName("test-list");

$app->get("/problems/{pid:[0-9]+}/tests/new", function (Request $request, Response $response) {
    return $response;
})->setName("new-test");

$app->post("/problems/{pid:[0-9]+}/tests/new", function (Request $request, Response $response) {
    return $response;
});

$app->get("/problems/{pid:[0-9]+}/tests/{testid:[0-9]+}/", function (Request $request, Response $response) {
    return $response;
})->setName("test");

$app->get("/problems/{pid:[0-9]+}/tests/{testid:[0-9]+}/edit", function (Request $request, Response $response) {
    return $response;
})->setName("edit-test");

$app->post("/problems/{pid:[0-9]+}/tests/{testid:[0-9]+}/edit", function (Request $request, Response $response) {
    return $response;
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

$app->get("/submissions/{sid:[0-9]+}/", function (Request $request, Response $response) {
    return $response;
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
