<?php
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

require "../vendor/autoload.php";

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
    $view["loggedIn"] = false;
    return $view;
};
$container["db"] = function ($container) {
    $pdo = new PDO("pgsql:dbname=toyoj user=toyojweb");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
};

$app->get("/", function (Request $request, Response $response) {
    return $this->view->render($response, "index.html");
})->setName("index");

$app->get("/login", function (Request $request, Response $response) {
    return $this->view->render($response, "login.html");
})->setName("login");

$app->post("/login", function (Request $request, Response $response) {
    $response = $response->withStatus(303);
    $response = $response->withHeader("Location", $_SERVER['REQUEST_URI']);
    return $response;
});

$app->get("/logout", function (Request $request, Response $response) {
    return $this->view->render($response, "logout.html");
})->setName("logout");

$app->post("/logout", function (Request $request, Response $response) {
    $response = $response->withStatus(303);
    $response = $response->withHeader("Location", $_SERVER['REQUEST_URI']);
    return $response;
});

$app->get("/problems/", function (Request $request, Response $response) {
    $problems = $this->db->query("SELECT p.pid, p.title, p.create_date, p.manager, u.username AS manager_name, p.visible FROM problems AS p, users AS u WHERE p.manager = u.uid ORDER BY pid");
    return $this->view->render($response, "problems.html", array("problems" => $problems));
})->setName("problem-list");

$app->get("/problems/{pid:[0-9]+}/", function (Request $request, Response $response, array $args) {
    $pid = $args["pid"];
    $stmt = $this->db->prepare("SELECT p.pid, p.statement, p.title, p.create_date, p.manager, u.username AS manager_name, p.visible FROM problems AS p, users AS u WHERE p.manager = u.uid AND p.pid = :pid");
    $stmt->execute(array(":pid" => $pid));
    $problem = $stmt->fetch();
    return $this->view->render($response, "problem.html", array("problem" => $problem));
})->setName("problem");

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
    return $response;
})->setName("signup");

$app->post("/signup", function (Request $request, Response $response) {
    return $response;
});

$app->get("/submissions/", function (Request $request, Response $response) {
    return $response;
})->setName("submission-list");

$app->get("/submissions/{sid:[0-9]+}/", function (Request $request, Response $response) {
    return $response;
})->setName("submission");

$app->get("/users/", function (Request $request, Response $response) {
    $users = $this->db->query("SELECT uid, username, register_date FROM users ORDER BY uid");
    return $this->view->render($response, "users.html", array("users" => $users));
})->setName("user-list");

$app->get("/users/{uid}/", function (Request $request, Response $response) {
    return $response;
})->setName("user");

$app->run();
