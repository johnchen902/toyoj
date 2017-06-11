<?php
namespace Toyoj\Controllers;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
class Utilities {
    public static function transactional($c, callable $callback) {
        $c->db->exec("BEGIN TRANSACTION ISOLATION LEVEL SERIALIZABLE");
        try {
            $result = call_user_func($callback);
        } catch (\Exception $e) {
            $c->db->exec("ROLLBACK");
            throw $e;
        }
        $c->db->exec("COMMIT");
        return $result;
    }
    public static function redirect(Response $response,
            int $code, string $location) {
        $response = $response->withStatus($code);
        $response = $response->withHeader("Location", $location);
        return $response;
    }
    public static function redirectRoute(Response $response,
            int $code, $c, string $routeName, array $routeData = [],
            array $routeQueryParams = []) {
        $path = $c->router->pathFor($routeName, $routeData, $routeQueryParams);
        return self::redirect($response, $code, $path);
    }
    public static function crlf2lf(string $str) {
        return str_replace("\r\n", "\n", $str);
    }
    public static function validateString(array &$e,
            string $str, string $name, int $minl, int $maxl) {
        $len = strlen($str);
        if($len < $minl)
            if($minl == 1)
                $e[] = "$name is empty.";
            else
                $e[] = "$name is shorter than $minl characters.";
        if($len > $maxl)
            $e[] = "$name is longer than $maxl characters.";
    }
}
?>
