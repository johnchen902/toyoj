<?php
namespace Toyoj\Controllers;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
class Account {
    public static function showLoginPage($c, Request $request, Response $response) {
        return $c->view->render($response, "login.html");
    }
    public static function login($c, Request $request, Response $response) {
        if($c->login->isLoggedIn()) {
            Utilities::errorMessage($c, "Already logged in");
            return Utilities::redirectRoute($response, 303, $c, "index");
        }

        $username = $request->getParsedBodyParam("username");
        $password = $request->getParsedBodyParam("password");

        $q = $c->qf->newSelect()
            ->cols(["id", "password_hash"])
            ->from("users")
            ->innerJoin("password_logins", "id = user_id")
            ->where("username = ?", $username);
        $user = $c->db->fetchOne($q->getStatement(), $q->getBindValues());

        if(!$user) {
            Utilities::errorMessage($c, "No such user.");
            return Utilities::redirectRoute($response, 303, $c, "login");
        }
        if(!password_verify($password, $user["password_hash"])) {
            Utilities::errorMessage($c, "Incorrect username or password.");
            return Utilities::redirectRoute($response, 303, $c, "login");
        }

        $c->login->login($user["id"]);
        Utilities::successMessage($c, "Logged in successfully");
        return Utilities::redirectRoute($response, 303, $c, "index");
    }
    public static function showLogoutPage($c, Request $request, Response $response) {
        return $c->view->render($response, "logout.html");
    }
    public static function logout($c, Request $request, Response $response) {
        if($c->login->isLoggedIn())
            $c->login->logout();
        Utilities::successMessage($c, "Logged out successfully");
        return Utilities::redirectRoute($response, 303, $c, "login");
    }
    public static function showSignupPage($c, Request $request, Response $response) {
        return $c->view->render($response, "signup.html");
    }
    public static function signup($c, Request $request, Response $response) {
        return Utilities::redirectRoute($response, 303, $c, "signup");
    }
};
?>
