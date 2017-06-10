<?php
namespace Toyoj\Controllers;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
class User {
    public static function showAll($c, Request $request, Response $response) {
        $q = self::baseUserQuery($c)->orderBy(["id"]);
        $users = $c->db->fetchAll($q->getStatement(), $q->getBindValues());

        return $c->view->render($response, "user-list.html",
                ["users" => $users]);
    }

    public static function show($c, Request $request, Response $response) {
        $user_id = $request->getAttribute("uid");
        $q = self::baseUserQuery($c)->where("id = ?", $user_id);
        $user = $c->db->fetchOne($q->getStatement(), $q->getBindValues());
        if(!$user)
            return ($c->errorview)($response, 404, "No Such User");

        return $c->view->render($response, "user.html",
                ["user" => $user]);
    }

    public static function baseUserQuery($c) {
        return $c->qf->newSelect()
            ->cols([
                "id",
                "username",
                "register_time",
            ])
            ->from("users");
    }
}
?>
