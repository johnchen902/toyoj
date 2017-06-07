<?php
namespace Toyoj\Controllers;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
class Submission {
    public static function showAll($c, Request $request, Response $response) {
        $q = self::baseSubmissionQuery($c)
            ->orderBy(["s.id DESC"]);
        $submissions = $c->db->fetchAll(
                $q->getStatement(), $q->getBindValues());

        return $c->view->render($response, "submission-list.html",
                ["submissions" => $submissions]);
    }

    public static function show($c, Request $request, Response $response) {
        $sid = $request->getAttribute("sid");
        $q = self::baseSubmissionQuery($c)
            ->cols(["s.code"])
            ->where("s.id = ?", $sid);
        $submission = $c->db->fetchOne(
                $q->getStatement(), $q->getBindValues());
        if(!$submission)
            return ($c->errorview)($response, 404, "No Such Submission");

        $q = $c->qf->newSelect()
            ->cols([
                "subtask_id",
                "accepted",
                "rejected",
                "time",
                "memory",
                "minscore",
                "maxscore",
                "fullscore",
                "judge_time",
            ])
            ->from("subtask_results_view")
            ->where("submission_id = ?", $sid)
            ->orderBy(["subtask_id ASC"]);
        $subtasks = $c->db->fetchAll(
                $q->getStatement(), $q->getBindValues());
        $submission["subtasks"] = $subtasks;

        $q = $c->qf->newSelect()
            ->cols([
                "testcase_id",
                "accepted",
                "verdict",
                "time",
                "memory",
                "judge_name",
                "judge_time",
            ])
            ->from("results_view")
            ->where("submission_id = ?", $sid)
            ->orderBy(["testcase_id ASC"]);
        $testcases = $c->db->fetchAll(
                $q->getStatement(), $q->getBindValues());
        $submission["testcases"] = $testcases;

        return $c->view->render($response, "submission.html",
                ["submission" => $submission]);
    }

    private static function baseSubmissionQuery($c) {
        return $c->qf->newSelect()
            ->cols([
                "s.id",
                "s.problem_id",
                "p.title AS problem_title",
                "s.submitter_id",
                "u.username AS submitter_username",
                "r.accepted",
                "r.rejected",
                "r.minscore",
                "r.maxscore",
                "r.fullscore",
                "r.time",
                "r.memory",
                "s.language_name",
                "s.submit_time",
                "r.judge_time",
            ])
            ->from("submissions AS s")
            ->innerJoin("problems AS p", "s.problem_id = p.id")
            ->innerJoin("users AS u", "s.submitter_id = u.id")
            ->leftJoin("submission_results_view AS r",
                    "s.id = r.submission_id");
    }
}
?>

