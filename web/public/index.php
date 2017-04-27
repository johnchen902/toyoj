<?php
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

require "../vendor/autoload.php";

$config["displayErrorDetails"] = true;

$app = new \Slim\App(["settings" => $config]);
$container = $app->getContainer();
$container["view"] = function ($container) {
    $view = new \Slim\Views\Twig("../templates", [
    ]);
    // See https://www.slimframework.com/docs/features/templates.html
    $basePath = rtrim(str_ireplace("index.php", "", $container["request"]->getUri()->getBasePath()), "/");
    $view->addExtension(new Slim\Views\TwigExtension($container["router"], $basePath));
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
    return $response;
})->setName("problem-list");

$app->get("/problems/{pid}/", function (Request $request, Response $response) {
    return $response;
})->setName("problem");

$app->get("/problems/{pid}/edit", function (Request $request, Response $response) {
    return $response;
})->setName("edit-problem");

$app->post("/problems/{pid}/edit", function (Request $request, Response $response) {
    return $response;
});

$app->get("/problems/{pid}/tests/", function (Request $request, Response $response) {
    return $response;
})->setName("test-list");

$app->get("/problems/{pid}/tests/{testid}/", function (Request $request, Response $response) {
    return $response;
})->setName("test");

$app->get("/problems/{pid}/tests/{testid}/edit", function (Request $request, Response $response) {
    return $response;
})->setName("edit-test");

$app->post("/problems/{pid}/tests/{testid}/edit", function (Request $request, Response $response) {
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

$app->get("/submissions/{sid}/", function (Request $request, Response $response) {
    return $response;
})->setName("submission");

$app->get("/users/", function (Request $request, Response $response) {
    return $response;
})->setName("user-list");

$app->get("/users/{uid}/", function (Request $request, Response $response) {
    return $response;
})->setName("user");

$app->run();
