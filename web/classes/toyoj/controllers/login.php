<?php
namespace Toyoj\Controllers;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use \redirect as redirect;
class Login {
    public static function get($c, Request $request, Response $response) {
        return $c->view->render($response, "login.html");
    }
    public static function post($c, Request $request, Response $response) {
        if($c->session["login"] ?? false) {
            $c->messages[] = "Already logged in";
            return redirect($response, 303, $c->router->pathFor("index"));
        }

        $username = $request->getParsedBodyParam("username");
        $password = $request->getParsedBodyParam("password");
        $stmt = $c->db->prepare("SELECT id, password_hash FROM users JOIN password_logins ON (users.id = password_logins.user_id) WHERE username=:username");
        $stmt->execute(["username" => $username]);
        $user = $stmt->fetch();
        if(!$user) {
            $c->messages[] = "Incorrect username or password";
            return redirect($response, 303, $c->router->pathFor("login"));
        }
        if(!password_verify($password, $user["password_hash"])) {
            $c->messages[] = "Incorrect username or password";
            return redirect($response, 303, $c->router->pathFor("login"));
        }

        $c->session["login"] = $user["id"];

        $c->messages[] = "Logged in successfully";
        return redirect($response, 303, $c->router->pathFor("index"));
    }
};
?>
