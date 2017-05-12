<?php
namespace Toyoj;
// TODO some queries are redundant; optimize later
class PermissionChecker {
    private $c; // dependency injection container
    public function __construct($c) {
        $this->c = $c;
    }
    private function getLogin() {
        return $this->c->session["login"];
    }
    private function prepare(string $queryString) {
        return $this->c->db->prepare($queryString);
    }

    public function checkSubmit($pid) {
        $login = $this->getLogin();
        if(!$login)
            return false;
        $stmt = $this->prepare("SELECT 1 FROM problems WHERE pid = :pid AND (ready OR manager = :login)");
        $stmt->execute(array(":pid" => $pid, ":login" => $login));
        return $stmt->rowCount() > 0;
    }

    public function checkNewProblem() {
        $login = $this->getLogin();
        if(!$login)
            return false;
        $stmt = $this->prepare("SELECT 1 FROM permissions WHERE uid = :login");
        $stmt->execute(array(":login" => $login));
        return $stmt->rowCount() > 0;
    }
    public function checkEditProblem($pid) {
        $login = $this->getLogin();
        if(!$login)
            return false;
        $stmt = $this->prepare("SELECT 1 FROM problems WHERE pid = :pid AND manager = :login");
        $stmt->execute(array(":pid" => $pid, ":login" => $login));
        return $stmt->rowCount() > 0;
    }
    public function checkNewSubtask($pid) {
        return $this->checkEditProblem($pid);
    }
    public function checkDeleteSubtask($subtaskid) {
        return $this->checkEditSubtask($subtaskid);
    }
    public function checkEditSubtask($subtaskid) {
        $login = $this->getLogin();
        if(!$login)
            return false;
        $stmt = $this->prepare("SELECT 1 FROM problems p, subtasks s WHERE p.pid = s.pid AND s.subtaskid = :subtaskid AND p.manager = :login");
        $stmt->execute(array(":subtaskid" => $subtaskid, ":login" => $login));
        return $stmt->rowCount() > 0;
    }
    public function checkNewTestCase($pid) {
        return $this->checkEditProblem($pid);
    }
    public function checkEditTestCase($caseid) {
        $login = $this->getLogin();
        if(!$login)
            return false;
        $stmt = $this->prepare("SELECT 1 FROM problems p, testcases t WHERE p.pid = t.pid AND t.testcaseid = :caseid AND p.manager = :login");
        $stmt->execute(array(":caseid" => $caseid, ":login" => $login));
        return $stmt->rowCount() > 0;
    }
};
?>
