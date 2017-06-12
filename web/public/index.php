<?php
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use Aura\Sql\ExtendedPdo;
use Aura\SqlQuery\QueryFactory;

set_include_path(get_include_path() . PATH_SEPARATOR . "../classes/");
spl_autoload_register();
require "../vendor/autoload.php";

date_default_timezone_set("Asia/Taipei");

$config["displayErrorDetails"] = true;

$app = new \Slim\App(["settings" => $config]);
$container = $app->getContainer();
$container["view"] = function ($container) {
    $view = new \Slim\Views\Twig("../templates", [
        'strict_variables' => true,
    ]);
    $basePath = rtrim(str_ireplace("index.php", "", $container["request"]->getUri()->getBasePath()), "/");
    $view->addExtension(new Slim\Views\TwigExtension($container["router"], $basePath));
    $view->getEnvironment()->addFilter(new Twig_Filter('markdown', function($string) {
        return Parsedown::instance()->setMarkupEscaped(true)->text($string);
    }, array("is_safe" => array("html"))));
    $view["container"] = $container;
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
    $session_factory = new \Aura\Session\SessionFactory;
    return $session_factory->newInstance($_COOKIE);
};
$container["login"] = function ($container) {
    return new \Toyoj\Login($container);
};
$container["messages"] = function ($container) {
    return new \Toyoj\Messages($container);
};

$app->get("/", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Index::get($this, $request, $response);
})->setName("index");

$app->get("/login", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Account::showLoginPage($this, $request, $response);
})->setName("login");
$app->post("/login", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Account::login($this, $request, $response);
});

$app->get("/logout", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Account::showLogoutPage($this, $request, $response);
})->setName("logout");
$app->post("/logout", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Account::logout($this, $request, $response);
});

$app->get("/signup", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Account::showSignupPage($this, $request, $response);
})->setName("signup");
$app->post("/signup", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Account::signup($this, $request, $response);
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

$app->get("/problems/{problem_id:[0-9]+}/", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Problem::show($this, $request, $response);
})->setName("problem");
$app->post("/problems/{problem_id:[0-9]+}/", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Problem::submit($this, $request, $response);
});

$app->get("/problems/{problem_id:[0-9]+}/edit", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Problem::showEditPage($this, $request, $response);
})->setName("problem-edit");
$app->post("/problems/{problem_id:[0-9]+}/edit", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Problem::edit($this, $request, $response);
});

$app->get("/problems/{problem_id:[0-9]+}/subtasks/", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Subtask::showAll($this, $request, $response);
})->setName("subtask-list");

$app->get("/problems/{problem_id:[0-9]+}/subtasks/new", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Subtask::showCreatePage($this, $request, $response);
})->setName("subtask-new");
$app->post("/problems/{problem_id:[0-9]+}/subtasks/new", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Subtask::create($this, $request, $response);
});

$app->get("/problems/{problem_id:[0-9]+}/subtasks/{subtask_id:[0-9]+}/", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Subtask::show($this, $request, $response);
})->setName("subtask");

$app->get("/problems/{problem_id:[0-9]+}/subtasks/{subtask_id:[0-9]+}/edit", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Subtask::showEditPage($this, $request, $response);
})->setName("subtask-edit");
$app->post("/problems/{problem_id:[0-9]+}/subtasks/{subtask_id:[0-9]+}/edit", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Subtask::editOrDelete($this, $request, $response);
});

$app->get("/problems/{problem_id:[0-9]+}/testcases/", function (Request $request, Response $response, array $args) {
    return \Toyoj\Controllers\TestCase::showAll($this, $request, $response);
})->setName("testcase-list");

$app->get("/problems/{problem_id:[0-9]+}/testcases/new", function (Request $request, Response $response, array $args) {
    return \Toyoj\Controllers\TestCase::showCreatePage($this, $request, $response);
})->setName("testcase-new");
$app->post("/problems/{problem_id:[0-9]+}/testcases/new", function (Request $request, Response $response, array $args) {
    return \Toyoj\Controllers\TestCase::create($this, $request, $response);
});

$app->get("/problems/{problem_id:[0-9]+}/testcases/{testcase_id:[0-9]+}/", function (Request $request, Response $response, array $args) {
    return \Toyoj\Controllers\TestCase::show($this, $request, $response);
})->setName("testcase");

$app->get("/problems/{problem_id:[0-9]+}/testcases/{testcase_id:[0-9]+}/edit", function (Request $request, Response $response, array $args) {
    return \Toyoj\Controllers\TestCase::showEditPage($this, $request, $response);
})->setName("testcase-edit");
$app->post("/problems/{problem_id:[0-9]+}/testcases/{testcase_id:[0-9]+}/edit", function (Request $request, Response $response, array $args) {
    return \Toyoj\Controllers\TestCase::editOrDelete($this, $request, $response);
});

$app->get("/submissions/", function (Request $request, Response $response) {
    return \Toyoj\Controllers\Submission::showAll($this, $request, $response);
})->setName("submission-list");

$app->get("/submissions/{submission_id:[0-9]+}/", function (Request $request, Response $response, array $args) {
    return \Toyoj\Controllers\Submission::show($this, $request, $response);
})->setName("submission");

$app->get("/users/", function (Request $request, Response $response) {
    return \Toyoj\Controllers\User::showAll($this, $request, $response);
})->setName("user-list");

$app->get("/users/{user_id:[0-9]+}/", function (Request $request, Response $response, array $args) {
    return \Toyoj\Controllers\User::show($this, $request, $response);
})->setName("user");

$app->run();
