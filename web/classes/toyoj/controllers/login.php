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

        $q = $c->qf->newSelect()
            ->cols(["id", "password_hash"])
            ->from("users")
            ->innerJoin("password_logins", "id = user_id")
            ->where("username = ?", $username)
            ;
        $user = $c->db->fetchOne($q->getStatement(), $q->getBindValues());

        $ok = $user && password_verify($password, $user["password_hash"]);
        if(!$ok) {
            $c->messages[] = "Incorrect username or password";
            return redirect($response, 303, $c->router->pathFor("login"));
        }

        $c->session["login"] = $user["id"];
        $c->messages[] = "Logged in successfully";
        return redirect($response, 303, $c->router->pathFor("index"));
    }
};
?>
