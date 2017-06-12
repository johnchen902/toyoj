<?php
namespace Toyoj\Controllers;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
abstract class AbstractPostHandler {
    protected function getAttributeNames() {
        return [];
    }

    protected function getFieldNames() {
        return [];
    }

    protected function getData(Request $request) {
        $data = [];
        foreach($this->getFieldNames() as $name)
            $data[$name] = $request->getParsedBodyParam($name);
        foreach($this->getAttributeNames() as $name)
            $data[$name] = $request->getAttribute($name);
        $this->transformData($data);
        return $data;
    }

    protected function transformData(array &$data) {
    }

    protected function verifyData(array $data) {
        return null;
    }

    protected function checkPermission($c, array $data) {
        return true;
    }

    protected function getSuccessMessage() {
        return "Success.";
    }

    protected function getPermissionDeniedMessage() {
        return "Permission Denied.";
    }

    protected function getUnknownErrorMessage() {
        return "Unknown Error.";
    }

    protected abstract function getSuccessLocation($c, array $data, $result);

    protected abstract function getErrorLocation(
        $c, array $data, \Exception $e);

    protected function locationToResponse(
            string $location, Response $response) {
        return Utilities::redirect($response, 303, $location);
    }

    public function handle($c, Request $request, Response $response) {
        $location = $this->handle0($c, $request);
        return $this->locationToResponse($location, $response);
    }

    private function handle0($c, Request $request) {
        $data = $this->getData($request);
        try {
            $result = $this->doHandle($c, $data);
        } catch(\Exception $e) {
            $errmsg = $e->getMessage() ?: $this->getUnknownErrorMessage();
            if($errmsg)
                Utilities::errorMessage($c, $errmsg);
            return $this->getErrorLocation($c, $data, $e);
        }
        $msg = $this->getSuccessMessage();
        if($msg)
            Utilities::successMessage($c, $msg);
        return $this->getSuccessLocation($c, $data, $result);
    }

    private function doHandle($c, array $data) {
        $errors = $this->verifyData($data);
        if($errors) {
            if(is_array($errors))
                $errors = join("\n", $errors);
            throw new \Exception($errors);
        }

        return Utilities::transactional($c, function () use ($c, $data) {
            if(!$this->checkPermission($c, $data))
                throw new \Exception($this->getPermissionDeniedMessage());
            return $this->transaction($c, $data);
        });
    }

    protected abstract function transaction($c, array $data);
};
?>
